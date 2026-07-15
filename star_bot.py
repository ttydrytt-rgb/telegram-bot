"""
⭐ TELEGRAM STARS REFER & EARN BOT ⭐
--------------------------------------------------
Features:
 - Force Join (PRIVATE channels supported) - dynamic list, admin can add/remove
 - Referral system (earn Stars per verified referral)
 - Stars Wallet + Withdraw (minimum 15 Stars)
 - Leaderboard, Stats
 - Admin Panel: Manage Channels (Add/Remove), Approve/Reject withdrawals
 - Colored buttons (primary / success / danger) - Bot API 9.4+
 - Premium custom emojis on buttons & messages

Requirements:
    pip install python-telegram-bot==22.7

IMPORTANT - PRIVATE CHANNEL SETUP:
    1. Add this bot as ADMIN in your private channel
       (needs "Invite Users via Link" permission at minimum).
    2. In the bot, as admin, open Admin Panel -> Manage Channels -> Add Channel.
    3. Forward ANY message from that private channel to the bot.
    4. Bot auto-detects the channel and tries to auto-generate an invite link.
       If it can't (missing permission), it will ask you to paste an
       invite link manually.
    Users will then see a "Join <Channel>" button using that invite link,
    and the bot verifies membership via get_chat_member(chat_id, user_id)
    -- this works for private channels too, as long as the BOT is a member/admin.

IMPORTANT - PREMIUM EMOJI ON BUTTONS:
    icon_custom_emoji_id on BUTTONS only renders if the bot owner's
    Telegram account has an active Premium subscription, or the bot
    purchased a Fragment username. Emoji inside message TEXT
    (<tg-emoji>) works regardless.
"""

import os
import sqlite3
import time
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.ext import (
    Application, CommandHandler, CallbackQueryHandler, MessageHandler,
    ContextTypes, filters
)

# ================= CONFIG =================
# Railway pe ye values "Variables" tab me set karo (code me mat likho)
BOT_TOKEN = os.environ["BOT_TOKEN"]
ADMIN_ID = int(os.environ["ADMIN_ID"])          # your Telegram user id
BOT_USERNAME = os.environ.get("BOT_USERNAME", "YourBotUsername")   # without @

REWARD_PER_REFERRAL = 1        # stars per successful referral
MIN_WITHDRAW = 15              # minimum stars required to withdraw

# Premium custom emoji IDs (from Telegram Premium emoji pack)
EMOJI = {
    "star":   "5929169225045249724",   # ⭐
    "check":  "6120473214507290876",   # ✅
    "money":  "6120520167089771101",   # 💰
    "rocket": "5927274934014316713",   # 🚀
    "crown":  "6120766436219555441",   # 👑
    "fire":   "4956606007221421405",   # ❤️‍🔥
    "bolt":   "4958479549265347295",   # ⚡️
    "eyes":   "4958617898751886363",   # 👀
    "gear":   "6120674060062953151",   # ⚙️ (fallback icon)
}

DB = "bot_data.db"

# in-memory admin flow state: {admin_id: "awaiting_forward" | "awaiting_link"}
ADMIN_STATE = {}
PENDING_CHANNEL = {}  # {admin_id: {"chat_id":.., "title":..}}

# ================= DATABASE =================
def db():
    conn = sqlite3.connect(DB)
    conn.execute("""CREATE TABLE IF NOT EXISTS users(
        user_id INTEGER PRIMARY KEY,
        username TEXT,
        stars INTEGER DEFAULT 0,
        referrals INTEGER DEFAULT 0,
        referred_by INTEGER,
        verified INTEGER DEFAULT 0,
        joined_at INTEGER
    )""")
    conn.execute("""CREATE TABLE IF NOT EXISTS withdraws(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        amount INTEGER,
        status TEXT DEFAULT 'pending',
        ts INTEGER
    )""")
    conn.execute("""CREATE TABLE IF NOT EXISTS channels(
        chat_id INTEGER PRIMARY KEY,
        title TEXT,
        invite_link TEXT
    )""")
    return conn

def get_user(uid):
    c = db()
    row = c.execute("SELECT * FROM users WHERE user_id=?", (uid,)).fetchone()
    c.close()
    return row

def add_user(uid, username, ref_by=None):
    c = db()
    if not c.execute("SELECT 1 FROM users WHERE user_id=?", (uid,)).fetchone():
        c.execute(
            "INSERT INTO users(user_id, username, referred_by, joined_at) VALUES(?,?,?,?)",
            (uid, username, ref_by, int(time.time()))
        )
        c.commit()
    c.close()

def mark_verified_and_reward(uid):
    c = db()
    row = c.execute("SELECT verified, referred_by FROM users WHERE user_id=?", (uid,)).fetchone()
    if row and row[0] == 0:
        c.execute("UPDATE users SET verified=1 WHERE user_id=?", (uid,))
        ref_by = row[1]
        if ref_by and ref_by != uid:
            c.execute("UPDATE users SET stars = stars + ?, referrals = referrals + 1 WHERE user_id=?",
                      (REWARD_PER_REFERRAL, ref_by))
        c.commit()
    c.close()
    return row[1] if row else None

def get_channels():
    c = db()
    rows = c.execute("SELECT chat_id, title, invite_link FROM channels").fetchall()
    c.close()
    return rows

def add_channel(chat_id, title, invite_link):
    c = db()
    c.execute("INSERT OR REPLACE INTO channels(chat_id, title, invite_link) VALUES(?,?,?)",
              (chat_id, title, invite_link))
    c.commit(); c.close()

def remove_channel(chat_id):
    c = db()
    c.execute("DELETE FROM channels WHERE chat_id=?", (chat_id,))
    c.commit(); c.close()

# ================= EMOJI / BUTTON HELPERS =================
def e(name):
    fallback = {"star": "⭐", "check": "✅", "money": "💰", "rocket": "🚀",
                "crown": "👑", "fire": "🔥", "bolt": "⚡️", "eyes": "👀", "gear": "⚙️"}
    eid = EMOJI.get(name)
    return f'<tg-emoji emoji-id="{eid}">{fallback[name]}</tg-emoji>'

def btn(text, callback_data, style=None, icon=None):
    kwargs = {}
    if style:
        kwargs["style"] = style          # "primary" | "success" | "danger"
    if icon:
        kwargs["icon_custom_emoji_id"] = EMOJI.get(icon)
    return InlineKeyboardButton(text, callback_data=callback_data, **kwargs)

# ================= FORCE JOIN CHECK (works for private channels) =================
async def not_joined_channels(context, user_id):
    missing = []
    for chat_id, title, invite_link in get_channels():
        try:
            member = await context.bot.get_chat_member(chat_id, user_id)
            if member.status in ("left", "kicked"):
                missing.append((chat_id, title, invite_link))
        except Exception:
            missing.append((chat_id, title, invite_link))
    return missing

def join_keyboard(missing):
    rows = []
    for chat_id, title, invite_link in missing:
        rows.append([InlineKeyboardButton(f"📢 Join {title}", url=invite_link)])
    rows.append([btn("I've Joined — Verify", "verify_join", style="success", icon="check")])
    return InlineKeyboardMarkup(rows)

# ================= MAIN MENU =================
def main_menu(is_admin=False):
    rows = [
        [btn("Refer & Earn", "menu_refer", style="primary", icon="rocket"),
         btn("My Balance", "menu_balance", style="success", icon="money")],
        [btn("Withdraw", "menu_withdraw", style="primary", icon="money"),
         btn("My Stats", "menu_stats", icon="eyes")],
        [btn("Leaderboard", "menu_leaderboard", icon="crown")],
        [btn("Help / Support", "menu_help")],
    ]
    if is_admin:
        rows.append([btn("Admin Panel", "admin_panel", style="danger", icon="gear")])
    return InlineKeyboardMarkup(rows)

def admin_panel_menu():
    rows = [
        [btn("Manage Channels", "adm_channels", style="primary", icon="gear")],
        [btn("Pending Withdrawals", "adm_pending", style="success", icon="money")],
        [btn("⬅️ Back to Menu", "menu_back")],
    ]
    return InlineKeyboardMarkup(rows)

def channels_menu():
    rows = []
    for chat_id, title, invite_link in get_channels():
        rows.append([
            InlineKeyboardButton(f"📢 {title}", url=invite_link),
            btn("❌ Remove", f"adm_rm_{chat_id}", style="danger")
        ])
    rows.append([btn("➕ Add Channel", "adm_add_channel", style="success", icon="check")])
    rows.append([btn("⬅️ Back", "admin_panel")])
    return InlineKeyboardMarkup(rows)

# ================= HANDLERS =================
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user
    args = context.args
    ref_by = None
    if args and args[0].startswith("ref_"):
        try:
            ref_by = int(args[0].replace("ref_", ""))
        except ValueError:
            ref_by = None
    add_user(user.id, user.username or user.first_name, ref_by)

    missing = await not_joined_channels(context, user.id)
    if missing:
        text = (f'{e("bolt")} <b>Welcome, {user.first_name}!</b>\n\n'
                f'{e("check")} Please join all channels below to unlock the bot:')
        await update.message.reply_text(text, parse_mode=ParseMode.HTML,
                                         reply_markup=join_keyboard(missing))
        return

    mark_verified_and_reward(user.id)
    await send_main_menu(update.message, user)

async def send_main_menu(message, user):
    text = (f'{e("star")} <b>Welcome, {user.first_name}!</b>\n\n'
            f'{e("money")} Earn Telegram Stars by referring friends.\n'
            f'{e("fire")} Reward per referral: <b>{REWARD_PER_REFERRAL} ⭐</b>\n'
            f'{e("check")} Minimum withdraw: <b>{MIN_WITHDRAW} ⭐</b>\n\n'
            f'Choose an option below:')
    await message.reply_text(text, parse_mode=ParseMode.HTML,
                              reply_markup=main_menu(is_admin=(user.id == ADMIN_ID)))

async def admin_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if update.effective_user.id != ADMIN_ID:
        return
    await update.message.reply_text(
        f'{e("gear")} <b>Admin Panel</b>', parse_mode=ParseMode.HTML,
        reply_markup=admin_panel_menu())

# ---- captures forwarded channel message for "Add Channel" flow ----
async def catch_forward(update: Update, context: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    if uid != ADMIN_ID or ADMIN_STATE.get(uid) != "awaiting_forward":
        return
    fwd_chat = update.message.forward_from_chat
    if not fwd_chat:
        await update.message.reply_text("❌ That's not a forwarded channel message. Try again.")
        return
    chat_id, title = fwd_chat.id, fwd_chat.title
    PENDING_CHANNEL[uid] = {"chat_id": chat_id, "title": title}

    # try auto-generate invite link
    try:
        link_obj = await context.bot.create_chat_invite_link(chat_id)
        add_channel(chat_id, title, link_obj.invite_link)
        ADMIN_STATE.pop(uid, None)
        PENDING_CHANNEL.pop(uid, None)
        await update.message.reply_text(
            f'{e("check")} Channel "<b>{title}</b>" added successfully!\nInvite link auto-generated.',
            parse_mode=ParseMode.HTML, reply_markup=channels_menu())
    except Exception:
        ADMIN_STATE[uid] = "awaiting_link"
        await update.message.reply_text(
            f"⚠️ Couldn't auto-generate invite link (bot may lack permission).\n"
            f"Please send the invite link for \"{title}\" manually now.")

# ---- captures manual invite link text ----
async def catch_link(update: Update, context: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    if uid != ADMIN_ID or ADMIN_STATE.get(uid) != "awaiting_link":
        return
    link = update.message.text.strip()
    if not link.startswith("https://t.me/"):
        await update.message.reply_text("❌ Invalid link. Send a valid https://t.me/... invite link.")
        return
    pending = PENDING_CHANNEL.get(uid)
    if not pending:
        ADMIN_STATE.pop(uid, None)
        return
    add_channel(pending["chat_id"], pending["title"], link)
    ADMIN_STATE.pop(uid, None)
    PENDING_CHANNEL.pop(uid, None)
    await update.message.reply_text(
        f'{e("check")} Channel "<b>{pending["title"]}</b>" added successfully!',
        parse_mode=ParseMode.HTML, reply_markup=channels_menu())

async def button_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    uid = q.from_user.id
    await q.answer()

    if q.data == "verify_join":
        missing = await not_joined_channels(context, uid)
        if missing:
            await q.answer(f"❌ Still missing {len(missing)} channel(s)!", show_alert=True)
            await q.edit_message_reply_markup(reply_markup=join_keyboard(missing))
            return
        mark_verified_and_reward(uid)
        await q.edit_message_text(f'{e("check")} <b>Verified successfully!</b>', parse_mode=ParseMode.HTML)
        await send_main_menu(q.message, q.from_user)
        return

    row = get_user(uid)
    if not row or row[5] == 0:
        await q.answer("⚠️ Please complete /start first.", show_alert=True)
        return

    if q.data == "menu_refer":
        link = f"https://t.me/{BOT_USERNAME}?start=ref_{uid}"
        text = (f'{e("rocket")} <b>Your Referral Link:</b>\n<code>{link}</code>\n\n'
                f'Share this with friends. You earn <b>{REWARD_PER_REFERRAL} ⭐</b> '
                f'per friend who joins and verifies.')
        await q.edit_message_text(text, parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))

    elif q.data == "menu_balance":
        text = f'{e("money")} <b>Your Stars Balance</b>\n\n⭐ Stars: <b>{row[2]}</b>\n👥 Referrals: <b>{row[3]}</b>'
        await q.edit_message_text(text, parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))

    elif q.data == "menu_withdraw":
        stars = row[2]
        if stars < MIN_WITHDRAW:
            text = (f'{e("check")} <b>Withdraw Locked</b>\n\nMinimum <b>{MIN_WITHDRAW} ⭐</b> required.\n'
                    f'You currently have <b>{stars} ⭐</b>.')
            await q.edit_message_text(text, parse_mode=ParseMode.HTML,
                reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))
        else:
            c = db()
            c.execute("INSERT INTO withdraws(user_id, amount, ts) VALUES(?,?,?)", (uid, stars, int(time.time())))
            c.execute("UPDATE users SET stars=0 WHERE user_id=?", (uid,))
            c.commit(); c.close()
            await q.edit_message_text(
                f'{e("fire")} <b>Withdraw request submitted!</b>\nAmount: {stars} ⭐\nStatus: Pending admin approval.',
                parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))
            await context.bot.send_message(
                ADMIN_ID, f"💵 New withdraw request\nUser: {uid}\nAmount: {stars} ⭐",
                reply_markup=InlineKeyboardMarkup([[
                    btn("Approve", f"adm_ok_{uid}", style="success", icon="check"),
                    btn("Reject", f"adm_no_{uid}", style="danger")]]))

    elif q.data == "menu_stats":
        text = f'{e("eyes")} <b>Your Stats</b>\n\n⭐ Stars: {row[2]}\n👥 Referrals: {row[3]}'
        await q.edit_message_text(text, parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))

    elif q.data == "menu_leaderboard":
        c = db()
        top = c.execute("SELECT username, referrals FROM users ORDER BY referrals DESC LIMIT 10").fetchall()
        c.close()
        text = f'{e("crown")} <b>Top Referrers</b>\n\n'
        for i, (uname, refs) in enumerate(top, 1):
            text += f"{i}. @{uname or 'user'} — {refs} referrals\n"
        await q.edit_message_text(text or "No data yet.", parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))

    elif q.data == "menu_help":
        await q.edit_message_text(f'{e("bolt")} Need help? Contact @YourSupportUsername',
            parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup([[btn("⬅️ Back", "menu_back")]]))

    elif q.data == "menu_back":
        await q.edit_message_text(f'{e("star")} <b>Main Menu</b>', parse_mode=ParseMode.HTML,
            reply_markup=main_menu(is_admin=(uid == ADMIN_ID)))

    # ---------- ADMIN ONLY ----------
    elif q.data == "admin_panel" and uid == ADMIN_ID:
        await q.edit_message_text(f'{e("gear")} <b>Admin Panel</b>', parse_mode=ParseMode.HTML,
            reply_markup=admin_panel_menu())

    elif q.data == "adm_channels" and uid == ADMIN_ID:
        chans = get_channels()
        text = f'{e("gear")} <b>Manage Force-Join Channels</b>\n\nCurrently {len(chans)} channel(s) added.'
        await q.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=channels_menu())

    elif q.data == "adm_add_channel" and uid == ADMIN_ID:
        ADMIN_STATE[uid] = "awaiting_forward"
        await q.edit_message_text(
            f'{e("check")} <b>Add Channel</b>\n\n'
            f'1. Make sure this bot is an <b>admin</b> in your private channel.\n'
            f'2. Now forward any message from that channel here.',
            parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup([[btn("⬅️ Cancel", "adm_channels")]]))

    elif q.data.startswith("adm_rm_") and uid == ADMIN_ID:
        chat_id = int(q.data.replace("adm_rm_", ""))
        remove_channel(chat_id)
        chans = get_channels()
        text = f'{e("check")} Channel removed.\n\nCurrently {len(chans)} channel(s) added.'
        await q.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=channels_menu())

    elif q.data == "adm_pending" and uid == ADMIN_ID:
        c = db()
        rows = c.execute("SELECT id, user_id, amount FROM withdraws WHERE status='pending'").fetchall()
        c.close()
        if not rows:
            text = f'{e("check")} No pending withdrawals.'
            await q.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=admin_panel_menu())
        else:
            text = f'{e("money")} <b>Pending Withdrawals</b>\n\n'
            kb = []
            for wid, user_id, amount in rows:
                text += f"User {user_id} — {amount} ⭐\n"
                kb.append([btn(f"✅ Approve {user_id}", f"adm_ok_{user_id}", style="success"),
                           btn(f"❌ Reject {user_id}", f"adm_no_{user_id}", style="danger")])
            kb.append([btn("⬅️ Back", "admin_panel")])
            await q.edit_message_text(text, parse_mode=ParseMode.HTML, reply_markup=InlineKeyboardMarkup(kb))

    elif q.data.startswith("adm_ok_") and uid == ADMIN_ID:
        target = int(q.data.replace("adm_ok_", ""))
        c = db()
        c.execute("UPDATE withdraws SET status='approved' WHERE user_id=? AND status='pending'", (target,))
        c.commit(); c.close()
        await context.bot.send_message(target, f'{e("check")} Your withdrawal was approved!', parse_mode=ParseMode.HTML)
        await q.edit_message_text("✅ Approved.")

    elif q.data.startswith("adm_no_") and uid == ADMIN_ID:
        target = int(q.data.replace("adm_no_", ""))
        c = db()
        row2 = c.execute("SELECT amount FROM withdraws WHERE user_id=? AND status='pending'", (target,)).fetchone()
        c.execute("UPDATE withdraws SET status='rejected' WHERE user_id=? AND status='pending'", (target,))
        if row2:
            c.execute("UPDATE users SET stars = stars + ? WHERE user_id=?", (row2[0], target))
        c.commit(); c.close()
        await context.bot.send_message(target, "❌ Your withdrawal was rejected. Stars refunded.")
        await q.edit_message_text("❌ Rejected.")

# ================= MAIN =================
def main():
    app = Application.builder().token(BOT_TOKEN).build()
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("admin", admin_cmd))
    app.add_handler(CallbackQueryHandler(button_handler))
    app.add_handler(MessageHandler(filters.FORWARDED & filters.User(ADMIN_ID), catch_forward))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND & filters.User(ADMIN_ID), catch_link))
    print("Bot running...")
    app.run_polling()

if __name__ == "__main__":
    main()