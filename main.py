import asyncio
import logging
import traceback

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode

from config import BOT_TOKEN, BOT_NAME, OWNER_IDS
import database.db as db

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
logger = logging.getLogger(__name__)


async def auto_add_owners_as_admins():
    """يضيف أرقام المالكين من config كأدمن تلقائياً عند تشغيل البوت"""
    for owner_id in OWNER_IDS:
        if not await db.is_admin(owner_id):
            await db.add_admin(owner_id)
            logger.info(f"✅ Added owner {owner_id} as admin automatically")
        else:
            logger.info(f"✅ Owner {owner_id} is already an admin")
            if await db.is_admin(owner_id):
                logger.info(f"🔥 Owner {owner_id} is CONFIRMED as admin in database")


async def run_bot():
    if not BOT_TOKEN:
        raise RuntimeError("BOT_TOKEN is not set!")

    logger.info("Initialising database...")
    await db.get_db()
    logger.info("Database ready.")
    
    await auto_add_owners_as_admins()

    bot = Bot(token=BOT_TOKEN, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
    dp = Dispatcher()

    # ✅ الحل: استورد الراوتر الرئيسي بس من commands
    from commands import router as main_router
    
    # ✅ استورد راوتر الادمن الجديد
    from admin import router as admin_router
    
    # ✅ ضيفهم مرة واحدة بس
    dp.include_router(main_router)
    dp.include_router(admin_router)

    @dp.error()
    async def error_handler(event, data):
        logger.error(f"Handler error: {data.get('exception')}", exc_info=True)

    logger.info(f"Starting {BOT_NAME} bot...")
    try:
        await bot.delete_webhook(drop_pending_updates=True)
        await dp.start_polling(bot, allowed_updates=["message", "callback_query"])
    finally:
        await db.close()
        await bot.session.close()


async def main():
    retries = 0
    while True:
        try:
            retries = 0
            await run_bot()
            break
        except KeyboardInterrupt:
            break
        except Exception as e:
            retries += 1
            logger.error(f"Bot crashed (attempt {retries}): {e}")
            if retries >= 10:
                break
            await asyncio.sleep(min(5 * retries, 60))


if __name__ == "__main__":
    asyncio.run(main())