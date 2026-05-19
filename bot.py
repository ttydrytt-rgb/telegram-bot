
# ==========================================
# SUPER UTILITY TELEGRAM BOT
# FULL FIXED SOURCE CODE + GEMINI AI
# ==========================================

# INSTALL:
# pkg update -y
# pkg install python -y
# pip install pyTelegramBotAPI flask requests google-generativeai

import telebot
from telebot.types import *
import sqlite3
import random
import requests
import google.generativeai as genai
from flask import Flask
from threading import Thread

# ==========================================
# BOT CONFIG
# ==========================================

TOKEN = "8993139884:AAFu9k_nMKGnedkzAVo-s2lZ34CI1jTjWvI"

# 👇 APNA GEMINI API KEY YAHA DALO
GEMINI_API_KEY = "AIzaSyCLwGIwnqr6K88Lk3iqWHAjfwtdo4YHjvM"

ADMIN_IDS = [8738016341]

CHANNEL_USERNAME = "IPLxTOSS01"

bot = telebot.TeleBot(TOKEN)

# ==========================================
# GEMINI CONFIG
# ==========================================

genai.configure(api_key=GEMINI_API_KEY)

model = genai.GenerativeModel("gemini-2.0-flash")

# ==========================================
# DATABASE FUNCTIONS
# ==========================================

def get_db():
    conn = sqlite3.connect("bot.db")
    return conn, conn.cursor()

conn, cursor = get_db()

cursor.execute("""
CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY,
    balance INTEGER DEFAULT 0,
    ref_by INTEGER DEFAULT 0,
    vip TEXT DEFAULT 'No',
    banned TEXT DEFAULT 'No'
)
""")

conn.commit()
conn.close()

# ==========================================
# WEB PANEL
# ==========================================

app = Flask(__name__)

@app.route('/')
def home():

    conn, cursor = get_db()

    total = cursor.execute(
        "SELECT COUNT(*) FROM users"
    ).fetchone()[0]

    vip = cursor.execute(
        "SELECT COUNT(*) FROM users WHERE vip='Yes'"
    ).fetchone()[0]

    conn.close()

    return f"""
    <h1>🔥 SUPER BOT ADMIN PANEL</h1>
    <h2>👥 Total Users: {total}</h2>
    <h2>👑 VIP Users: {vip}</h2>
    """

def run():
    app.run(host="0.0.0.0", port=8080)

def keep_alive():
    t = Thread(target=run)
    t.start()

keep_alive()

# ==========================================
# FORCE JOIN CHECK
# ==========================================

def check_join(user_id):

    try:

        member = bot.get_chat_member(
            f"@{CHANNEL_USERNAME}",
            user_id
        )

        return member.status in [
            'member',
            'administrator',
            'creator'
        ]

    except:
        return False

# ==========================================
# START COMMAND
# ==========================================

@bot.message_handler(commands=['start'])
def start(message):

    user_id = message.from_user.id

    conn, cursor = get_db()

    if not check_join(user_id):

        markup = InlineKeyboardMarkup()

        markup.add(
            InlineKeyboardButton(
                "📢 JOIN CHANNEL",
                url=f"https://t.me/{CHANNEL_USERNAME}"
            )
        )

        bot.send_message(
            message.chat.id,
            "⚠️ Please Join Channel First",
            reply_markup=markup
        )

        conn.close()
        return

    cursor.execute(
        "INSERT OR IGNORE INTO users(id) VALUES(?)",
        (user_id,)
    )

    conn.commit()

    # REFERRAL SYSTEM

    ref = message.text.split()

    if len(ref) > 1:

        try:

            ref_by = int(ref[1])

            if ref_by != user_id:

                cursor.execute(
                    "UPDATE users SET balance = balance + 5 WHERE id=?",
                    (ref_by,)
                )

                conn.commit()

        except:
            pass

    markup = ReplyKeyboardMarkup(resize_keyboard=True)

    markup.row("🎯 Prediction", "🤖 AI Chat")
    markup.row("📂 Store File", "💰 Balance")
    markup.row("🎁 Daily Bonus", "🎡 Spin")
    markup.row("👑 VIP", "📢 Referral")

    bot.send_message(
        message.chat.id,
        f"""
🔥 Welcome {message.from_user.first_name}

🚀 SUPER UTILITY BOT

✅ AI Chat
✅ IPL Prediction
✅ File Store
✅ Referral System
✅ Daily Rewards
✅ Admin Panel
✅ Gemini AI
""",
        reply_markup=markup
    )

    conn.close()

# ==========================================
# PREDICTION BUTTON
# ==========================================

@bot.message_handler(func=lambda m: m.text == "🎯 Prediction")
def prediction(message):

    teams = [
        "CSK",
        "MI",
        "RCB",
        "KKR",
        "SRH",
        "RR",
        "GT",
        "LSG"
    ]

    winner = random.choice(teams)

    bot.reply_to(
        message,
        f"🏏 Today's Winning Prediction:\n\n🔥 {winner}"
    )

# ==========================================
# BALANCE BUTTON
# ==========================================

@bot.message_handler(func=lambda m: m.text == "💰 Balance")
def balance(message):

    conn, cursor = get_db()

    bal = cursor.execute(
        "SELECT balance FROM users WHERE id=?",
        (message.from_user.id,)
    ).fetchone()

    conn.close()

    if bal:
        bot.reply_to(
            message,
            f"💰 Your Balance: {bal[0]} Coins"
        )

# ==========================================
# DAILY BONUS
# ==========================================

@bot.message_handler(func=lambda m: m.text == "🎁 Daily Bonus")
def daily_bonus(message):

    conn, cursor = get_db()

    cursor.execute(
        "UPDATE users SET balance = balance + 10 WHERE id=?",
        (message.from_user.id,)
    )

    conn.commit()
    conn.close()

    bot.reply_to(
        message,
        "🎁 Daily Bonus Added: 10 Coins"
    )

# ==========================================
# SPIN
# ==========================================

@bot.message_handler(func=lambda m: m.text == "🎡 Spin")
def spin(message):

    reward = random.randint(1, 50)

    conn, cursor = get_db()

    cursor.execute(
        "UPDATE users SET balance = balance + ? WHERE id=?",
        (reward, message.from_user.id)
    )

    conn.commit()
    conn.close()

    bot.reply_to(
        message,
        f"🎰 You Won {reward} Coins!"
    )

# ==========================================
# REFERRAL
# ==========================================

@bot.message_handler(func=lambda m: m.text == "📢 Referral")
def referral(message):

    bot.reply_to(
        message,
        f"👥 Your Referral Link:\n\nhttps://t.me/{bot.get_me().username}?start={message.from_user.id}"
    )

# ==========================================
# VIP
# ==========================================

@bot.message_handler(func=lambda m: m.text == "👑 VIP")
def vip(message):

    bot.reply_to(
        message,
        "👑 VIP Feature Coming Soon"
    )

# ==========================================
# GEMINI AI CHAT
# ==========================================

@bot.message_handler(func=lambda m: m.text == "🤖 AI Chat")
def ai_mode(message):

    msg = bot.reply_to(
        message,
        "🤖 Send Your Question"
    )

    bot.register_next_step_handler(
        msg,
        gemini_chat
    )

def gemini_chat(message):

    try:

        response = model.generate_content(message.text)

        bot.reply_to(
            message,
            f"🤖 AI Response:\n\n{response.text}"
        )

    except Exception as e:

        bot.reply_to(
            message,
            f"❌ Error:\n{e}"
        )

# ==========================================
# ADMIN PANEL
# ==========================================

@bot.message_handler(commands=['admin'])
def admin(message):

    if message.from_user.id not in ADMIN_IDS:
        return

    conn, cursor = get_db()

    total = cursor.execute(
        "SELECT COUNT(*) FROM users"
    ).fetchone()[0]

    vip = cursor.execute(
        "SELECT COUNT(*) FROM users WHERE vip='Yes'"
    ).fetchone()[0]

    conn.close()

    bot.send_message(
        message.chat.id,
        f"""
👑 ADMIN PANEL

👥 Total Users: {total}
👑 VIP Users: {vip}
"""
    )

# ==========================================
# BOT START
# ==========================================

print("🔥 BOT STARTED")

bot.infinity_polling()
