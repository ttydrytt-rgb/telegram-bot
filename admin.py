# admin.py
import asyncio
from aiogram import Router, Bot, F
from aiogram.types import Message, CallbackQuery, InlineKeyboardMarkup, InlineKeyboardButton
from aiogram.filters import Command, CommandObject
from aiogram.enums import ParseMode

import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import database.db as db
from config import OWNER_IDS, BOT_NAME
from functions.emojis import EMOJI, EMOJI_PLAIN

router = Router()

async def is_admin_or_owner(user_id: int) -> bool:
    return await db.is_admin(user_id) or user_id in OWNER_IDS

@router.message(Command("admin", prefix="/."))
async def cmd_admin_panel(msg: Message):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        return
    
    text = (
        f"「 {EMOJI['bolt']} ADMIN PANEL 」\n\n"
        f"Welcome, <b>{msg.from_user.first_name}</b>.\n"
        f"Manage users, codes, and bot settings.\n\n"
        f"<i>Use the buttons below:</i>"
    )
    
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['stats']} Stats", callback_data="admin_stats"),
         InlineKeyboardButton(text=f"{EMOJI_PLAIN['ticket']} Codes", callback_data="admin_codes_menu")],
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['users']} Users", callback_data="admin_users_menu"),
         InlineKeyboardButton(text=f"{EMOJI_PLAIN['ban']} Ban/Unban", callback_data="admin_ban_menu")],
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['broadcast']} Broadcast", callback_data="admin_broadcast")]
    ])
    
    await msg.answer(text, reply_markup=kb, parse_mode=ParseMode.HTML)

@router.callback_query(F.data == "admin_stats")
async def admin_stats(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    stats = await db.get_global_stats()
    text = (
        f"「 {EMOJI['stats']} GLOBAL STATS 」\n\n"
        f"👥 <b>Users:</b> {stats['users']}\n"
        f"🔨 <b>Banned:</b> {stats['banned']}\n"
        f"💳 <b>Total Checks:</b> {stats['checks']}\n"
        f"✅ <b>Charged:</b> {stats['charged']}\n"
        f"🔥 <b>Live:</b> {stats['live']}\n"
        f"🎫 <b>Active Codes:</b> {stats['active_codes']}"
    )
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['back']} Back", callback_data="admin_back_to_main")]
    ])
    await call.message.edit_text(text, reply_markup=kb, parse_mode=ParseMode.HTML)
    await call.answer()


@router.callback_query(F.data == "admin_codes_menu")
async def admin_codes_menu(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="➕ Create Code", callback_data="admin_create_code")],
        [InlineKeyboardButton(text="📋 List Active Codes", callback_data="admin_list_codes")],
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['back']} Back", callback_data="admin_back_to_main")]
    ])
    await call.message.edit_text("「 🎫 CODE MANAGEMENT 」\n\nChoose an action:", reply_markup=kb, parse_mode=ParseMode.HTML)
    await call.answer()

@router.callback_query(F.data == "admin_create_code")
async def admin_create_code_prompt(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    await call.message.edit_text(
        "「 ➕ CREATE CODE 」\n\n"
        "Send the code details in this format:\n"
        "<code>/makecode &lt;plan&gt; &lt;days&gt; &lt;hits_per_day&gt; &lt;max_uses&gt;</code>\n\n"
        "Example:\n"
        "<code>/makecode premium 30 500 1</code>\n\n"
        "<i>• plan: any name (e.g., vip, premium)\n"
        "• days: validity period\n"
        "• hits_per_day: 0 for unlimited\n"
        "• max_uses: how many users can use it</i>",
        parse_mode=ParseMode.HTML
    )
    await call.answer()

@router.message(Command("makecode", prefix="/."))
async def cmd_makecode(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        return
    
    if not command.args:
        return await msg.answer("Usage: <code>/makecode plan days hits_per_day max_uses</code>", parse_mode=ParseMode.HTML)
    
    args = command.args.split()
    if len(args) != 4:
        await msg.answer("Usage: <code>/makecode plan days hits_per_day max_uses</code>", parse_mode=ParseMode.HTML)
        return
    
    plan_type, days, hpd, max_uses = args[0], int(args[1]), int(args[2]), int(args[3])
    code = await db.create_redeem_code(plan_type, days, hpd, max_uses, uid)
    await msg.answer(
        f"{EMOJI['charged']} <b>Code Created!</b>\n\n"
        f"<code>{code}</code>\n\n"
        f"Plan: {plan_type}\nDays: {days}\nHits/Day: {hpd if hpd > 0 else 'Unlimited'}\nMax Uses: {max_uses}",
        parse_mode=ParseMode.HTML
    )

@router.callback_query(F.data == "admin_list_codes")
async def admin_list_codes(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    codes = await db.get_active_codes()
    if not codes:
        text = "No active codes found."
    else:
        lines = [f"• <code>{c['code']}</code> | {c['plan_type']} | Used {c['used_count']}/{c['max_uses']}" for c in codes[:10]]
        text = "「 ACTIVE CODES 」\n\n" + "\n".join(lines)
    
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=f"{EMOJI_PLAIN['back']} Back", callback_data="admin_codes_menu")]
    ])
    await call.message.edit_text(text, reply_markup=kb, parse_mode=ParseMode.HTML)
    await call.answer()


@router.callback_query(F.data == "admin_ban_menu")
async def admin_ban_menu(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    await call.message.edit_text(
        "「 🔨 BAN / UNBAN 」\n\n"
        "Commands:\n"
        "<code>/ban &lt;user_id&gt;</code> - Ban user\n"
        "<code>/unban &lt;user_id&gt;</code> - Unban user\n\n"
        "Example:\n<code>/ban 123456789</code>",
        parse_mode=ParseMode.HTML
    )
    await call.answer()

@router.message(Command("ban", prefix="/."))
async def cmd_ban(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        return
    
    if not command.args:
        return await msg.answer("Usage: <code>/ban user_id</code>", parse_mode=ParseMode.HTML)
    
    try:
        target_id = int(command.args.strip())
    except ValueError:
        return await msg.answer("Invalid user ID.")
    
    await db.ban_user(target_id)
    await msg.answer(f"{EMOJI['ban']} User <code>{target_id}</code> has been banned.", parse_mode=ParseMode.HTML)

@router.message(Command("unban", prefix="/."))
async def cmd_unban(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        return
    
    if not command.args:
        return await msg.answer("Usage: <code>/unban user_id</code>", parse_mode=ParseMode.HTML)
    
    try:
        target_id = int(command.args.strip())
    except ValueError:
        return await msg.answer("Invalid user ID.")
    
    await db.unban_user(target_id)
    await msg.answer(f"{EMOJI['charged']} User <code>{target_id}</code> has been unbanned.", parse_mode=ParseMode.HTML)

ADMIN_BROADCAST = {}

@router.callback_query(F.data == "admin_broadcast")
async def admin_broadcast_prompt(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    ADMIN_BROADCAST[call.from_user.id] = True
    await call.message.edit_text(
        "「 📣 BROADCAST 」\n\n"
        "Send me the message you want to broadcast to all users.\n"
        "You can send text, photo, or any media.\n\n"
        "<i>Type /cancel to abort.</i>",
        parse_mode=ParseMode.HTML
    )
    await call.answer()

@router.message(Command("cancel"))
async def cancel_broadcast(msg: Message):
    if ADMIN_BROADCAST.pop(msg.from_user.id, None):
        await msg.answer("Broadcast cancelled.")
    else:
        await msg.answer("No active broadcast.")

@router.message(lambda msg: ADMIN_BROADCAST.get(msg.from_user.id, False))
async def process_broadcast(msg: Message, bot: Bot):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        ADMIN_BROADCAST.pop(uid, None)
        return
    
    status_msg = await msg.answer(f"{EMOJI['hitting']} Broadcasting... This may take a while.")
    
    users = await db.get_all_user_ids()
    success, fail = 0, 0
    
    for user_id in users:
        try:
            if msg.photo:
                await bot.send_photo(user_id, msg.photo[-1].file_id, caption=msg.caption or "")
            elif msg.text:
                await bot.send_message(user_id, msg.text, parse_mode=ParseMode.HTML)
            success += 1
        except Exception:
            fail += 1
        await asyncio.sleep(0.05)
    
    ADMIN_BROADCAST.pop(uid, None)
    await status_msg.edit_text(f"{EMOJI['charged']} Broadcast complete.\n✅ Success: {success}\n❌ Failed: {fail}")

@router.callback_query(F.data == "admin_back_to_main")
async def admin_back_to_main(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return
    await cmd_admin_panel(call.message)
    await call.answer()

@router.callback_query(F.data == "admin_users_menu")
async def admin_users_menu(call: CallbackQuery):
    if not await is_admin_or_owner(call.from_user.id):
        return await call.answer("Not authorized", show_alert=True)
    
    await call.message.edit_text(
        "「 👥 USER MANAGEMENT 」\n\n"
        "Commands:\n"
        "<code>/addadmin &lt;user_id&gt;</code> - Add new admin\n"
        "<code>/rmadmin &lt;user_id&gt;</code> - Remove admin\n"
        "<code>/user &lt;user_id&gt;</code> - Get user info\n"
        "<code>/stats</code> - Global stats",
        parse_mode=ParseMode.HTML
    )
    await call.answer()

@router.message(Command("addadmin", prefix="/."))
async def cmd_addadmin(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if uid not in OWNER_IDS:
        return await msg.answer("Only the bot owner can add admins.")
    
    if not command.args:
        return await msg.answer("Usage: <code>/addadmin user_id</code>", parse_mode=ParseMode.HTML)
    
    try:
        target_id = int(command.args.strip())
    except ValueError:
        return await msg.answer("Invalid user ID.")
    
    await db.add_admin(target_id)
    await msg.answer(f"{EMOJI['charged']} User <code>{target_id}</code> is now an admin.", parse_mode=ParseMode.HTML)

@router.message(Command("rmadmin", prefix="/."))
async def cmd_rmadmin(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if uid not in OWNER_IDS:
        return await msg.answer("Only the bot owner can remove admins.")
    
    if not command.args:
        return await msg.answer("Usage: <code>/rmadmin user_id</code>", parse_mode=ParseMode.HTML)
    
    try:
        target_id = int(command.args.strip())
    except ValueError:
        return await msg.answer("Invalid user ID.")
    
    await db.remove_admin(target_id)
    await msg.answer(f"{EMOJI['declined']} User <code>{target_id}</code> is no longer an admin.", parse_mode=ParseMode.HTML)

@router.message(Command("user", prefix="/."))
async def cmd_user_info(msg: Message, command: CommandObject):
    uid = msg.from_user.id
    if not await is_admin_or_owner(uid):
        return
    
    if not command.args:
        return await msg.answer("Usage: <code>/user user_id</code>", parse_mode=ParseMode.HTML)
    
    try:
        target_id = int(command.args.strip())
    except ValueError:
        return await msg.answer("Invalid user ID.")
    
    plan = await db.get_user_plan(target_id)
    hits_today = await db.get_daily_hits(target_id)
    stats = await db.get_user_hit_stats(target_id)
    
    text = (
        f"「 👤 USER INFO 」\n\n"
        f"<b>ID:</b> <code>{target_id}</code>\n"
        f"<b>Plan:</b> {plan['label']}\n"
        f"<b>Expiry:</b> {plan['expiry'] or 'Never'}\n"
        f"<b>Today's Hits:</b> {hits_today}\n"
        f"<b>Total Hits:</b> {stats['total']}\n"
        f"<b>Charged:</b> {stats['charged']}\n"
        f"<b>Live:</b> {stats['live']}\n"
        f"<b>Declined:</b> {stats['declined']}"
    )
    await msg.answer(text, parse_mode=ParseMode.HTML)