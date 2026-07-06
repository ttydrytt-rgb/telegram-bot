import re
import os
import asyncio
import logging
import aiofiles
from urllib.parse import urlparse
from typing import List, Tuple, Optional

# Aiogram imports
from aiogram import Bot, Dispatcher, types, F
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton, FSInputFile
from aiogram.filters import Command, CommandObject
from aiogram.enums import ParseMode
from aiogram.client.default import DefaultBotProperties

from pyrogram import Client as PyroClient
from pyrogram.errors import (
    UserAlreadyParticipant,
    InviteHashExpired,
    InviteHashInvalid,
    PeerIdInvalid,
    InviteRequestSent
)
from pyrogram.types import Chat, ChatMember

from config import (
    API_ID,
    API_HASH,
    BOT_TOKEN,
    SESSION_STRING,
    ADMIN_LIMIT,
    ADMIN_IDS,
    DEFAULT_LIMIT
)



logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


PREMIUM_EMOJI_IDS = {
    "✅": "5444987348334965906", "❌": "5447647474984449520", "🔥": "5116414868357907335",
    "⚡": "5219943216781995020", "💳": "5447453226498552490", "💠": "4904936030232117798",
    "📝": "6266764202950530136", "🌐": "5447602197439218445", "📊": "5445146408153806223",
    "📦": "5303102515301083665", "📋": "4904936030232117798", "⏳": "5258113901106580375",
    "🚀": "5343887395894882351", "⚠️": "4915853119839011973", "💎": "5343636681473935403",
    "👋": "5134476056241112076", "💡": "5301275719681190738", "📈": "5134457377428341766",
    "🔢": "5444931419270839381", "🔌": "5120722716260828125", "⭐️": "5172716095697584957",
    "🆓": "5406756500108501710", "👑": "6266995104687330978", "🔍": "5258396243666681152",
    "⏱️": "5343927661213279013", "💥": "5122933683820430249", "🆔": "5447311106030726740",
    "👤": "5445174334031166029", "📅": "5343927661213279013", "🔄": "5454245266305604993",
    "🏦": "5445408306669582934", "🥰": "5444931419270839381", "😱": "5447181973544008180",
    "🔷": "5301275719681190738", "🔑": "5454386656628991407", "📆": "5343927661213279013",
    "👥": "5454371323595744068", "🥕": "5447653032672129347", "➡️": "5445350109862720603",
    "🦉": "5123344136665039833", "🍑": "5445408306669582934", "💪": "5305622454218024328",
    "🌝": "5341684837881235158", "📁": "5444908424015934570", "ℹ️": "5289930378885214069",
    "💀": "5231338559587257737", "📢": "5116445341150872576", "💰": "5116648080787112958",
    "🔘": "5219901967916084166", "🔗": "5447479640547428304", "👇": "5122933683820430249",
    "📌": "5447187153274567373", "🍳": "5305622454218024328", "💸": "5283232570660634549",
    "🎉": "5172632227871196306", "🎁": "5283031441637148958", "🚫": "5116151848855667552",
    "🛒": "5447319442562251569", "🔧": "4904936030232117798", "⛔️": "5275969776668134187",
    "🥲": "4904468402782864209", "☠️": "5231338559587257737", "🛡": "5219672809936006424",
    "📸": "5445344161333015312", "💬": "5447510826304959724", "😺": "5118590136149345664",
    "🌍": "5303440357428586778", "🔹": "5301275719681190738", "📹": "5445158077579952110",
    "📡": "5447448489149625830", "🌟": "5310224206732996002", "📍": "5447187153274567373",
    "🔐": "5258476306152038031", "😇": "6321225560789877992", "👌": "5445350109862720603",
    "⭐": "6267298050205553492", "🍭": "6267152480878990865", "⚙️": "5258023599419171861",
    "⛔": "4918014360267260850", "📥": "5350747347724810871", "💵": "5350711759625795085",
    "️🏷️": "5436285465420383204", "📂": "5444908424015934570", "🛠️": "5348239232852836489",
    "📄️": "5323538339062628165", "📎": "5282531402821991529", "🖥️": "5258574977633567931",
    "⌨️": "5258334330740171131", "🛡️": "5219672809936006424", "🔒": "5258476306152038031",
    "🔓": "5258476306152038031", "📤": "5350747347724810871", "🕒": "5258113901106580375",
}

def premium_emoji(text: str) -> str:
    if not text:
        return text
    result = text
    for emoji, emoji_id in PREMIUM_EMOJI_IDS.items():
        result = result.replace(emoji, f'<tg-emoji emoji-id="{emoji_id}">{emoji}</tg-emoji>')
    return result



user = PyroClient(
    "user_session",
    session_string=SESSION_STRING,
    workers=1000
)

bot = Bot(
    token=BOT_TOKEN,
    default=DefaultBotProperties(parse_mode=ParseMode.HTML)
)
dp = Dispatcher()


START_MESSAGE = premium_emoji(
    f"💠 𝗖𝗺𝗱 𝗔𝗳𝘂𝗼𝗻𝗮 𝗦𝗰𝗿𝗮𝗽𝗲𝗿 \n"
    f"\n"
    f"📝 <code>/scr [channel] [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗮 𝘀𝗶𝗻𝗴𝗹𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹 \n"
    f"📝 <code>/mc [channel1] [channel2] [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗺𝘂𝗹𝘁𝗶𝗽𝗹𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀 \n"
    f"📝 <code>/scall [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗔𝗟𝗟 𝗷𝗼𝗶𝗻𝗲𝗱 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀 🚀\n"
    f" \n"
    f"📌 𝗘𝘅𝗮𝗺𝗽𝗹𝗲𝘀:\n"
    f" \n"
    f"🔹 <code>/scr @approved_card3 100</code>\n"
    f"🔹 <code>/scr @approved_card3 100 515462</code>\n"
    f"🔹 <code>/scr @approved_card3 100 BankName</code>\n"
    f"🔹 <code>/scr approved_card3 100</code>\n"
    f"🔹 <code>/scr t.me/approved_card3 100</code>\n"
    f"🔹 <code>/scr https://t.me/approved_card5 100</code>\n"
    f"🔹 <code>/scr https://t.me/+jyKReyfczLE5YTZl 100</code>\n"
    f"🔹 <code>/scr https://t.me/+jyKReyfczLE5YTZl 100 515462</code>\n"
    f" 🔹 <code>/scall 100</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗮𝗹𝗹 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀 🔥\n"
)

WELCOME_MESSAGE = premium_emoji(
    "👋 𝗪𝗲𝗹𝗰𝗼𝗺𝗲 𝘁𝗼 𝗦𝗔𝗡𝗗𝗬 𝗦𝗰𝗿𝗮𝗽𝗲𝗿 𝗕𝗼𝘁!\n\n"
    "🔥 𝗘𝗻𝗷𝗼𝘆 𝘀𝗰𝗿𝗮𝗽𝗶𝗻𝗴!"
)

HELP_MESSAGE = premium_emoji(
    "📋 𝗖𝗼𝗺𝗺𝗮𝗻𝗱 𝗟𝗶𝘀𝘁:\n\n"
    "🔹 <code>/scr [channel] [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗮 𝘀𝗶𝗻𝗴𝗹𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹\n"
    "🔹 <code>/mc [channel1] [channel2] [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗺𝘂𝗹𝘁𝗶𝗽𝗹𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀\n"
    "🔹 <code>/scall [limit]</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗳𝗿𝗼𝗺 𝗔𝗟𝗟 𝗷𝗼𝗶𝗻𝗲𝗱 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀 🚀\n"
    "🔹 <code>/start</code> - 𝗦𝗵𝗼𝘄 𝗺𝗮𝗶𝗻 𝗺𝗲𝗻𝘂\n\n"
    "📌 𝗘𝘅𝗮𝗺𝗽𝗹𝗲𝘀:\n"
    "🔸 <code>/scr @approved_card3 100</code>\n"
    "🔸 <code>/mc @channel1 @channel2 50</code>\n"
    "🔸 <code>/scall 100</code> - 𝗦𝗰𝗿𝗮𝗽𝗲 𝗮𝗹𝗹 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀\n"
    "🔸 <code>/scr https://t.me/+jyKReyfczLE5YTZl 100 515462</code>"
)


async def scrape_messages(client, channel_username, limit, start_number=None, bank_name=None):
    messages = []
    count = 0
    pattern = r'\d{16}\D*\d{2}\D*\d{2,4}\D*\d{3,4}'

    logger.info(f"Starting to scrape messages from {channel_username} with limit {limit}")

    async for message in client.search_messages(channel_username):
        if count >= limit:
            break
        text = message.text or message.caption
        if text:
            if bank_name and bank_name.lower() not in text.lower():
                continue
            matched_messages = re.findall(pattern, text)
            if matched_messages:
                formatted_messages = []
                for matched_message in matched_messages:
                    extracted_values = re.findall(r'\d+', matched_message)
                    if len(extracted_values) == 4:
                        card_number, mo, year, cvv = extracted_values
                        year = year[-2:]
                        if start_number:
                            if card_number.startswith(start_number[:6]):
                                formatted_messages.append(f"{card_number}|{mo}|{year}|{cvv}")
                        else:
                            formatted_messages.append(f"{card_number}|{mo}|{year}|{cvv}")
                messages.extend(formatted_messages)
                count += len(formatted_messages)
    logger.info(f"Scraped {len(messages)} messages from {channel_username}")
    return messages[:limit]

def remove_duplicates(messages):
    unique_messages = list(set(messages))
    duplicates_removed = len(messages) - len(unique_messages)
    logger.info(f"Removed {duplicates_removed} duplicates")
    return unique_messages, duplicates_removed

async def join_private_chat(client, invite_link):
    try:
        await client.join_chat(invite_link)
        logger.info(f"Joined chat via invite link: {invite_link}")
        return True
    except UserAlreadyParticipant:
        logger.info(f"Already a participant in the chat: {invite_link}")
        return True
    except InviteRequestSent:
        logger.info(f"Join request sent to the chat: {invite_link}")
        return False
    except (InviteHashExpired, InviteHashInvalid) as e:
        logger.error(f"Failed to join chat {invite_link}: {e}")
        return False

async def send_join_request(client, invite_link):
    try:
        await client.join_chat(invite_link)
        logger.info(f"Sent join request to chat: {invite_link}")
        return True
    except PeerIdInvalid as e:
        logger.error(f"Failed to send join request to chat {invite_link}: {e}")
        return False
    except InviteRequestSent:
        logger.info(f"Join request sent to the chat: {invite_link}")
        return False


async def get_all_joined_channels(client):
    channels = []
    try:
        async for dialog in client.get_dialogs():
            if dialog.chat.type in ["channel", "supergroup"]:
                try:
                    member = await client.get_chat_member(dialog.chat.id, "me")
                    if member.status in ["member", "administrator", "creator"]:
                        channels.append({
                            "id": dialog.chat.id,
                            "title": dialog.chat.title,
                            "username": dialog.chat.username or "Private"
                        })
                        logger.info(f"Found channel: {dialog.chat.title} (ID: {dialog.chat.id})")
                except Exception as e:
                    logger.warning(f"Could not check membership for {dialog.chat.title}: {e}")
    except Exception as e:
        logger.error(f"Error getting dialogs: {e}")
    
    return channels


def get_main_menu_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="Mᴀɪɴ", url="https://t.me/SANDYxBIHARI", style="primary", icon_custom_emoji_id="5445408306669582934"),
            InlineKeyboardButton(text="Aᴘʀo CC", url="https://t.me/approved_card5", style="primary", icon_custom_emoji_id="5447210891558814377")
        ],
        [
            InlineKeyboardButton(text="Cᴀʀi", url="https://t.me/+jyKReyfczLE5YTZl", style="primary", icon_custom_emoji_id="5343927661213279013"),
            InlineKeyboardButton(text="Sᴄʀ B", url="http://t.me/Scrap_4_bot", style="primary", icon_custom_emoji_id="5219672809936006424")
        ],
        [
            InlineKeyboardButton(text="Sᴇᴛ", callback_data="show_cmd", style="success", icon_custom_emoji_id="5445408306669582934")
        ]
    ])


def get_back_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(
                text="Bᴀᴄᴋ",
                callback_data="back_to_menu",
                style="danger",
                icon_custom_emoji_id="5445365692004071819"
            )
        ]
    ])


async def get_user_link() -> str:
    return '<a href="https://t.me/SANDYxBIHARI">下</a>'

async def send_results(
    message: types.Message,
    unique_messages: List[str],
    duplicates_removed: int,
    source_name: str,
    bin_filter: Optional[str] = None,
    bank_filter: Optional[str] = None
):
    if not unique_messages:
        await message.edit_text(premium_emoji("❌ 𝗡𝗼 𝗖𝗿𝗲𝗱𝗶𝘁 𝗖𝗮𝗿𝗱 𝗙𝗼𝘂𝗻𝗱"))
        return

    file_name = f"x{len(unique_messages)}_{source_name.replace(' ', '_')}@scrc3bot.txt"
    
    async with aiofiles.open(file_name, mode='w') as f:
        await f.write("\n".join(unique_messages))
    
    user_link = await get_user_link()
    
    from datetime import datetime
    current_time = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    
    req_user = message.from_user.first_name
    if message.from_user.username:
        req_user = f'<a href="t.me/{message.from_user.username}">{message.from_user.first_name}</a>'
    
    caption = premium_emoji(
        f"💎 [{user_link}] 𝗔𝗳𝘂𝗼𝗻𝗮 𝗦𝗰𝗿𝗮𝗽𝗲𝗱\n"
        f"💬 [{user_link}] 𝗦𝗼𝘂𝗿𝗰𝗲: <code>{source_name}</code>\n"
        f"⛔️ [{user_link}] 𝗔𝗺𝗼𝘂𝗻𝘁: <code>{len(unique_messages)}</code>\n"
        f"⭐️ [{user_link}] 𝗥𝗲𝗺𝗼𝘃𝗲𝗱: <code>{duplicates_removed}</code>\n"
        f"﹏﹏﹏﹏﹏﹏﹏﹏﹏﹏﹏\n"
        f"☠️ [{user_link}] 𝗕𝗼𝘁 𝗕𝘆: <a href='https://t.me/SANDYxBIHARI'>𝗦𝗔𝗡𝗗𝗬</a>"
    )
    
    if bin_filter:
        caption = premium_emoji(f"🔍 [{user_link}] 𝗕𝗜𝗡 𝗙𝗶𝗹𝘁𝗲𝗿: <code>{bin_filter}</code>\n﹏﹏﹏﹏﹏﹏﹏﹏﹏\n") + caption
    if bank_filter:
        caption = premium_emoji(f"🏦 [{user_link}] 𝗕𝗮𝗻𝗸 𝗙𝗶𝗹𝘁𝗲𝗿: <code>{bank_filter}</code>\n﹏﹏﹏﹏﹏﹏﹏﹏\n") + caption

    await message.delete()
    
    await bot.send_document(
        chat_id=message.chat.id,
        document=FSInputFile(file_name),
        caption=caption,
        parse_mode=ParseMode.HTML
    )
    
    os.remove(file_name)
    logger.info(f"Results sent successfully for {source_name}")

@dp.message(Command("start"))
async def start_command(message: types.Message):
    try:
        photo = FSInputFile("cc.jpg")
        await bot.send_photo(
            chat_id=message.chat.id,
            photo=photo,
            caption=WELCOME_MESSAGE,
            reply_markup=get_main_menu_keyboard()
        )
    except FileNotFoundError:
        await message.reply(
            WELCOME_MESSAGE,
            reply_markup=get_main_menu_keyboard(),
            parse_mode=ParseMode.HTML
        )
    except Exception as e:
        logger.error(f"Error in start: {e}")
        await message.reply(
            WELCOME_MESSAGE,
            reply_markup=get_main_menu_keyboard(),
            parse_mode=ParseMode.HTML
        )

@dp.message(Command("help"))
async def help_command(message: types.Message):
    try:
        photo = FSInputFile("cc.jpg")
        await bot.send_photo(
            chat_id=message.chat.id,
            photo=photo,
            caption=HELP_MESSAGE,
            reply_markup=get_main_menu_keyboard()
        )
    except:
        await message.reply(
            HELP_MESSAGE,
            reply_markup=get_main_menu_keyboard(),
            parse_mode=ParseMode.HTML
        )

@dp.callback_query(F.data == "show_cmd")
async def show_cmd_callback(callback: types.CallbackQuery):
    try:
        await callback.message.edit_caption(
            caption=START_MESSAGE,
            reply_markup=get_back_keyboard(),
            parse_mode=ParseMode.HTML
        )
    except Exception as e:
        try:
            await callback.message.edit_text(
                START_MESSAGE,
                parse_mode=ParseMode.HTML,
                disable_web_page_preview=True,
                reply_markup=get_back_keyboard()
            )
        except:
            try:
                await callback.message.delete()
            except:
                pass
            await callback.message.answer(
                START_MESSAGE,
                parse_mode=ParseMode.HTML,
                disable_web_page_preview=True,
                reply_markup=get_back_keyboard()
            )
    await callback.answer()

@dp.callback_query(F.data == "back_to_menu")
async def back_to_menu_callback(callback: types.CallbackQuery):
    try:
        # تعديل الرسالة الحالية (اللي فيها الصورة) وتغيير النص والأزرار
        await callback.message.edit_caption(
            caption=WELCOME_MESSAGE,
            reply_markup=get_main_menu_keyboard(),
            parse_mode=ParseMode.HTML
        )
    except Exception as e:
        # لو فشل التعديل (مش رسالة فيها صورة)، جرب edit_text
        try:
            await callback.message.edit_text(
                WELCOME_MESSAGE,
                reply_markup=get_main_menu_keyboard(),
                parse_mode=ParseMode.HTML
            )
        except:
            # لو كل حاجة فشلت، امسح وابعت جديدة بالصورة
            try:
                await callback.message.delete()
            except:
                pass
            try:
                photo = FSInputFile("cc.jpg")
                await bot.send_photo(
                    chat_id=callback.message.chat.id,
                    photo=photo,
                    caption=WELCOME_MESSAGE,
                    reply_markup=get_main_menu_keyboard()
                )
            except:
                await bot.send_message(
                    chat_id=callback.message.chat.id,
                    text=WELCOME_MESSAGE,
                    reply_markup=get_main_menu_keyboard(),
                    parse_mode=ParseMode.HTML
                )
    await callback.answer()

@dp.message(Command("scr"))
async def scr_command(message: types.Message, command: CommandObject):
    """أمر السكراب من قناة واحدة"""
    args = command.args.split() if command.args else []
    
    if len(args) < 2:
        await message.reply(premium_emoji("⚠️ 𝗣𝗿𝗼𝘃𝗶𝗱𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲 𝗮𝗻𝗱 𝗮𝗺𝗼𝘂𝗻𝘁 𝘁𝗼 𝘀𝗰𝗿𝗮𝗽𝗲 ❌"))
        return
    
    channel_identifier = args[0]
    try:
        limit = int(args[1])
    except ValueError:
        await message.reply(premium_emoji("⚠️ 𝗜𝗻𝘃𝗮𝗹𝗶𝗱 𝗹𝗶𝗺𝗶𝘁 𝘃𝗮𝗹𝘂𝗲. 𝗽𝗿𝗼𝘃𝗶𝗱𝗲 𝗮 𝘃𝗮𝗹𝗶𝗱 𝗻𝘂𝗺𝗯𝗲𝗿"))
        return
    
    user_id = message.from_user.id
    max_lim = ADMIN_LIMIT if user_id in ADMIN_IDS else DEFAULT_LIMIT
    
    if limit > max_lim:
        await message.reply(premium_emoji(f"⚠️ 𝗔𝗺𝗼𝘂𝗻𝘁 𝗼𝘃𝗲𝗿 𝗠𝗮𝘅 𝗹𝗶𝗺𝗶𝘁 𝗶𝘀 {max_lim} "))
        return
    
    start_number = None
    bank_name = None
    bin_filter = None
    
    if len(args) > 2:
        if args[2].isdigit():
            start_number = args[2]
            bin_filter = args[2][:6]
        else:
            bank_name = " ".join(args[2:])
    
    temp_msg = await message.reply(premium_emoji("⏳ 𝗖𝗵𝗲𝗰𝗸𝗶𝗻𝗴 𝘁𝗵𝗲 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲..."))
    await asyncio.sleep(1.5)
    
    chat = None
    channel_name = ""
    channel_username = ""
    
    try:
        if channel_identifier.lstrip("-").isdigit():
            chat_id = int(channel_identifier)
            try:
                chat = await user.get_chat(chat_id)
                channel_name = chat.title
                logger.info(f"Scraping from private channel: {channel_name} (ID: {chat_id})")
            except Exception as e:
                await temp_msg.edit_text(premium_emoji("❌ 𝗜𝗻𝘃𝗮𝗹𝗶𝗱 𝗰𝗵𝗮𝘁 𝗜𝗗 "))
                return
        else:
            if channel_identifier.startswith("https://t.me/+"):
                invite_link = channel_identifier
                joined = await join_private_chat(user, invite_link)
                if not joined:
                    request_sent = await send_join_request(user, invite_link)
                    if not request_sent:
                        await temp_msg.edit_text(premium_emoji("❌ 𝗜𝗻𝘃𝗮𝗹𝗶𝗱 𝗨𝘀𝗲𝗿𝗻𝗮𝗺𝗲 𝗼𝗿 𝗖𝗵𝗮𝘁 ⚠️ 𝗧𝗿𝘆 𝗮𝗴𝗮𝗶𝗻 🔄"))
                        return
                else:
                    await temp_msg.delete()
                    chat = await user.get_chat(invite_link)
                    channel_name = chat.title
                    logger.info(f"Joined private channel via link: {channel_name}")
            elif channel_identifier.startswith("https://t.me/"):
                channel_username = channel_identifier[13:]
            elif channel_identifier.startswith("t.me/"):
                channel_username = channel_identifier[5:]
            else:
                channel_username = channel_identifier

            if not chat:
                try:
                    chat = await user.get_chat(channel_username)
                    channel_name = chat.title
                    logger.info(f"Scraping from public channel: {channel_name} (Username: {channel_username})")
                except Exception as e:
                    await temp_msg.edit_text(premium_emoji("❌ 𝗜𝗻𝗰𝗼𝗿𝗿𝗲𝗰𝘁 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲 𝗼𝗿 𝗰𝗵𝗮𝘁 𝗜𝗗 "))
                    return
                    
    except Exception as e:
        await temp_msg.edit_text(premium_emoji("❌ 𝗜𝗻𝗰𝗼𝗿𝗿𝗲𝗰𝘁 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲 𝗼𝗿 𝗰𝗵𝗮𝘁 𝗜𝗗 "))
        return
    
    await temp_msg.edit_text(premium_emoji("⏳ 𝗦𝗰𝗿𝗮𝗽𝗶𝗻𝗴 𝗜𝗻 𝗣𝗿𝗼𝗴𝗿𝗲𝘀𝘀..."))
    
    scrapped_results = await scrape_messages(
        user, 
        chat.id, 
        limit, 
        start_number=start_number,
        bank_name=bank_name
    )
    
    unique_messages, duplicates_removed = remove_duplicates(scrapped_results)
    await send_results(
        temp_msg,
        unique_messages,
        duplicates_removed,
        channel_name,
        bin_filter=bin_filter,
        bank_filter=bank_name
    )

@dp.message(Command("mc"))
async def mc_command(message: types.Message, command: CommandObject):
    """أمر السكراب من عدة قنوات"""
    args = command.args.split() if command.args else []
    
    if len(args) < 2:
        await message.reply(premium_emoji("⚠️ 𝗣𝗿𝗼𝘃𝗶𝗱𝗲 𝗮𝘁 𝗹𝗲𝗮𝘀𝘁 𝗼𝗻𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲"))
        return
    
    channel_identifiers = args[:-1]
    try:
        limit = int(args[-1])
    except ValueError:
        await message.reply(premium_emoji("⚠️ 𝗜𝗻𝘃𝗮𝗹𝗶𝗱 𝗹𝗶𝗺𝗶𝘁 𝘃𝗮𝗹𝘂𝗲. 𝗽𝗿𝗼𝘃𝗶𝗱𝗲 𝗮 𝘃𝗮𝗹𝗶𝗱 𝗻𝘂𝗺𝗯𝗲𝗿"))
        return
    
    user_id = message.from_user.id
    max_lim = ADMIN_LIMIT if user_id in ADMIN_IDS else DEFAULT_LIMIT
    
    if limit > max_lim:
        await message.reply(premium_emoji(f"⚠️ 𝗔𝗺𝗼𝘂𝗻𝘁 𝗼𝘃𝗲𝗿 𝗠𝗮𝘅 𝗹𝗶𝗺𝗶𝘁 𝗶𝘀 {max_lim} "))
        return
    
    temp_msg = await message.reply(premium_emoji("⏳ 𝗦𝗰𝗿𝗮𝗽𝗶𝗻𝗴 𝗜𝗻 𝗣𝗿𝗼𝗴𝗿𝗲𝘀𝘀..."))
    all_messages = []
    tasks = []

    for channel_identifier in channel_identifiers:
        parsed_url = urlparse(channel_identifier)
        channel_username = parsed_url.path.lstrip('/') if not parsed_url.scheme else channel_identifier
        tasks.append(scrape_messages_task(user, channel_username, limit, bot, message))

    results = await asyncio.gather(*tasks)
    for result in results:
        all_messages.extend(result)

    unique_messages, duplicates_removed = remove_duplicates(all_messages)
    unique_messages = unique_messages[:limit]

    if not unique_messages:
        await temp_msg.edit_text(premium_emoji("❌ 𝗡𝗼 𝗖𝗿𝗲𝗱𝗶𝘁 𝗖𝗮𝗿𝗱 𝗙𝗼𝘂𝗻𝗱"))
    else:
        await send_results(temp_msg, unique_messages, duplicates_removed, "Multiple Chats")

@dp.message(Command("scall"))
async def scall_command(message: types.Message, command: CommandObject):
    args = command.args.split() if command.args else []
    
    if len(args) < 1:
        await message.reply(premium_emoji("⚠️ 𝗣𝗿𝗼𝘃𝗶𝗱𝗲 𝘁𝗵𝗲 𝗹𝗶𝗺𝗶𝘁 𝘁𝗼 𝘀𝗰𝗿𝗮𝗽𝗲 ❌\n📌 𝗘𝘅𝗮𝗺𝗽𝗹𝗲: <code>/scall 100</code>"))
        return
    
    try:
        limit = int(args[0])
    except ValueError:
        await message.reply(premium_emoji("⚠️ 𝗜𝗻𝘃𝗮𝗹𝗶𝗱 𝗹𝗶𝗺𝗶𝘁 𝘃𝗮𝗹𝘂𝗲. 𝗽𝗿𝗼𝘃𝗶𝗱𝗲 𝗮 𝘃𝗮𝗹𝗶𝗱 𝗻𝘂𝗺𝗯𝗲𝗿"))
        return
    
    user_id = message.from_user.id
    max_lim = ADMIN_LIMIT if user_id in ADMIN_IDS else DEFAULT_LIMIT
    
    if limit > max_lim:
        await message.reply(premium_emoji(f"⚠️ 𝗔𝗺𝗼𝘂𝗻𝘁 𝗼𝘃𝗲𝗿 𝗠𝗮𝘅 𝗹𝗶𝗺𝗶𝘁 𝗶𝘀 {max_lim} "))
        return
    
    temp_msg = await message.reply(premium_emoji("⏳ 𝗚𝗲𝘁𝘁𝗶𝗻𝗴 𝗮𝗹𝗹 𝗷𝗼𝗶𝗻𝗲𝗱 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀..."))
    
    channels = await get_all_joined_channels(user)
    
    if not channels:
        await temp_msg.edit_text(premium_emoji("❌ 𝗡𝗼 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀 𝗳𝗼𝘂𝗻𝗱! 𝗠𝗮𝗸𝗲 𝘀𝘂𝗿𝗲 𝘆𝗼𝘂 𝗮𝗿𝗲 𝗷𝗼𝗶𝗻𝗲𝗱 𝘁𝗼 𝘀𝗼𝗺𝗲 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀."))
        return
    
    await temp_msg.edit_text(
        premium_emoji(f"📊 𝗙𝗼𝘂𝗻𝗱 <b>{len(channels)}</b> 𝗰𝗵𝗮𝗻𝗻𝗲𝗹𝘀\n⏳ 𝗦𝘁𝗮𝗿𝘁𝗶𝗻𝗴 𝘀𝗰𝗿𝗮𝗽𝗲...")
    )
    
    all_messages = []
    channel_names = []
    total_channels = len(channels)
    processed = 0
    
    for channel in channels:
        processed += 1
        channel_id = channel["id"]
        channel_title = channel["title"]
        channel_names.append(channel_title)
        
        await temp_msg.edit_text(
            premium_emoji(f"⏳ 𝗦𝗰𝗿𝗮𝗽𝗶𝗻𝗴 𝗰𝗵𝗮𝗻𝗻𝗲𝗹 {processed}/{total_channels}\n📁 {channel_title}")
        )
        
        try:
            scrapped = await scrape_messages(
                user,
                channel_id,
                limit,
                start_number=None,
                bank_name=None
            )
            all_messages.extend(scrapped)
            logger.info(f"Scraped {len(scrapped)} messages from {channel_title}")
        except Exception as e:
            logger.error(f"Error scraping {channel_title}: {e}")
            continue
    
    unique_messages, duplicates_removed = remove_duplicates(all_messages)
    unique_messages = unique_messages[:limit]
    
    if not unique_messages:
        await temp_msg.edit_text(premium_emoji("❌ 𝗡𝗼 𝗖𝗿𝗲𝗱𝗶𝘁 𝗖𝗮𝗿𝗱 𝗙𝗼𝘂𝗻𝗱 𝗶𝗻 𝗮𝗻𝘆 𝗰𝗵𝗮𝗻𝗻𝗲𝗹"))
    else:
        source_name = f"All Channels ({len(channels)})"
        await send_results(
            temp_msg,
            unique_messages,
            duplicates_removed,
            source_name
        )

async def scrape_messages_task(client, channel_username, limit, bot_client, message):
    try:
        chat = None
        if channel_username.startswith("https://t.me/+"):
            invite_link = channel_username
            temporary_msg = await bot_client.send_message(
                message.chat.id, 
                premium_emoji("⏳ 𝗖𝗵𝗲𝗰𝗸𝗶𝗻𝗴 𝗨𝘀𝗲𝗿𝗻𝗮𝗺𝗲...")
            )
            joined = await join_private_chat(client, invite_link)
            if not joined:
                request_sent = await send_join_request(client, invite_link)
                if not request_sent:
                    return []
            else:
                await temporary_msg.delete()
                chat = await client.get_chat(invite_link)
        else:
            chat = await client.get_chat(channel_username)

        return await scrape_messages(client, chat.id, limit)
    except Exception as e:
        await bot_client.send_message(
            message.chat.id, 
            premium_emoji(f"❌ 𝗜𝗻𝗰𝗼𝗿𝗿𝗲𝗰𝘁 𝘂𝘀𝗲𝗿𝗻𝗮𝗺𝗲 𝗳𝗼𝗿 {channel_username} ")
        )
        logger.error(f"Failed to scrape from {channel_username}: {e}")
        return []



async def main():
    try:
        logger.info("Starting Pyrogram client...")
        await user.start()
        logger.info("✅ Pyrogram client started successfully")
    except Exception as e:
        logger.error(f"❌ Failed to start Pyrogram: {e}")
        return
    
    try:
        logger.info("Starting Aiogram bot...")
        await bot.delete_webhook(drop_pending_updates=True)
        await dp.start_polling(bot)
    except Exception as e:
        logger.error(f"❌ Failed to start bot: {e}")
    
if __name__ == "__main__":
    asyncio.run(main())