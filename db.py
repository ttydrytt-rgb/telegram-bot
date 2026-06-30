import aiosqlite
import os
import json
import secrets
import string
import asyncio
from datetime import datetime, date, timedelta, timezone
from config import FREE_DAILY_LIMIT

DB_PATH = "bot_database.db"
_db_connection = None
_lock = asyncio.Lock()

async def get_db():
    global _db_connection
    async with _lock:
        if _db_connection is None:
            _db_connection = await aiosqlite.connect(DB_PATH)
            _db_connection.row_factory = aiosqlite.Row
            await _ensure_tables(_db_connection)
        return _db_connection

async def _ensure_tables(db):
    await db.execute("""
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            first_name TEXT,
            join_date TEXT,
            is_banned INTEGER DEFAULT 0,
            proxy_mode TEXT DEFAULT 'system',
            show_site TEXT DEFAULT 'ask'
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS user_plans (
            user_id INTEGER PRIMARY KEY,
            plan_type TEXT DEFAULT 'free',
            expiry_date TEXT,
            hits_per_day INTEGER DEFAULT 0
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS daily_hits (
            user_id INTEGER,
            hit_date TEXT,
            count INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, hit_date)
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS check_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            card TEXT,
            checkout_url TEXT,
            merchant TEXT,
            amount TEXT,
            status TEXT,
            response TEXT,
            time_taken REAL,
            timestamp TEXT
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS proxies (
            user_id INTEGER,
            proxy TEXT,
            PRIMARY KEY (user_id, proxy)
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS redeem_codes (
            code TEXT PRIMARY KEY,
            plan_type TEXT,
            days INTEGER,
            hits_per_day INTEGER,
            max_uses INTEGER,
            used_count INTEGER DEFAULT 0,
            created_by INTEGER,
            is_active INTEGER DEFAULT 1,
            created_at TEXT
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS code_uses (
            code TEXT,
            user_id INTEGER,
            used_at TEXT,
            PRIMARY KEY (code, user_id)
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS admins (
            user_id INTEGER PRIMARY KEY
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS saved_bins (
            user_id INTEGER,
            name TEXT,
            bin_value TEXT,
            created_at TEXT,
            PRIMARY KEY (user_id, name)
        )
    """)
    await db.execute("""
        CREATE TABLE IF NOT EXISTS bot_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    """)
    await db.commit()

# ─── User CRUD ───

async def upsert_user(user_id: int, username: str = None, first_name: str = None):
    db = await get_db()
    await db.execute("""
        INSERT INTO users (user_id, username, first_name, join_date)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id) DO UPDATE SET
            username = excluded.username,
            first_name = excluded.first_name
    """, (user_id, username or "", first_name or "", date.today().isoformat()))
    
    await db.execute("""
        INSERT INTO user_plans (user_id, plan_type, hits_per_day)
        VALUES (?, 'free', 0)
        ON CONFLICT(user_id) DO NOTHING
    """, (user_id,))
    await db.commit()

async def is_banned(user_id: int) -> bool:
    db = await get_db()
    async with db.execute("SELECT is_banned FROM users WHERE user_id = ?", (user_id,)) as cursor:
        row = await cursor.fetchone()
        return bool(row and row['is_banned'])

async def ban_user(user_id: int):
    db = await get_db()
    await db.execute("UPDATE users SET is_banned = 1 WHERE user_id = ?", (user_id,))
    await db.commit()

async def unban_user(user_id: int):
    db = await get_db()
    await db.execute("UPDATE users SET is_banned = 0 WHERE user_id = ?", (user_id,))
    await db.commit()

# ─── Proxy mode ───

async def get_user_proxy_mode(user_id: int) -> str:
    db = await get_db()
    async with db.execute("SELECT proxy_mode FROM users WHERE user_id = ?", (user_id,)) as cursor:
        row = await cursor.fetchone()
        return row['proxy_mode'] if row else "system"

async def set_user_proxy_mode(user_id: int, mode: str):
    db = await get_db()
    await db.execute("UPDATE users SET proxy_mode = ? WHERE user_id = ?", (mode, user_id))
    await db.commit()

# ─── Plans ───

async def get_user_plan(user_id: int) -> dict:
    db = await get_db()
    async with db.execute("SELECT * FROM user_plans WHERE user_id = ?", (user_id,)) as cursor:
        row = await cursor.fetchone()
        if not row:
            return {"type": "free", "label": "Free", "unlimited": False, "hits_per_day": 0, "expiry": None, "just_expired": False}
        
        plan_type = row['plan_type']
        expiry = row['expiry_date']
        hpd = row['hits_per_day'] or 0

        if plan_type != "free" and expiry:
            expiry_date = datetime.strptime(expiry, "%Y-%m-%d").date()
            if expiry_date < date.today():
                await db.execute("UPDATE user_plans SET plan_type = 'free', expiry_date = NULL, hits_per_day = 0 WHERE user_id = ?", (user_id,))
                await db.commit()
                return {"type": "free", "label": "Free", "unlimited": False, "hits_per_day": 0, "expiry": None, "just_expired": True, "expired_plan": plan_type}
            return {"type": plan_type, "label": plan_type, "unlimited": True, "hits_per_day": hpd, "expiry": expiry, "just_expired": False}
        
        return {"type": "free", "label": "Free", "unlimited": False, "hits_per_day": 0, "expiry": None, "just_expired": False}

async def set_user_plan(user_id: int, plan_type: str, days: int, hits_per_day: int = 0):
    expiry = (date.today() + timedelta(days=days)).strftime("%Y-%m-%d")
    db = await get_db()
    await db.execute("""
        INSERT INTO user_plans (user_id, plan_type, expiry_date, hits_per_day)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id) DO UPDATE SET
            plan_type = excluded.plan_type,
            expiry_date = excluded.expiry_date,
            hits_per_day = excluded.hits_per_day
    """, (user_id, plan_type, expiry, hits_per_day))
    await db.commit()

# ─── Daily hits ───

async def get_daily_hits(user_id: int) -> int:
    today = date.today().isoformat()
    db = await get_db()
    async with db.execute("SELECT count FROM daily_hits WHERE user_id = ? AND hit_date = ?", (user_id, today)) as cursor:
        row = await cursor.fetchone()
        return row['count'] if row else 0

async def increment_daily_hits(user_id: int) -> int:
    today = date.today().isoformat()
    db = await get_db()
    await db.execute("""
        INSERT INTO daily_hits (user_id, hit_date, count)
        VALUES (?, ?, 1)
        ON CONFLICT(user_id, hit_date) DO UPDATE SET count = count + 1
    """, (user_id, today))
    await db.commit()
    return await get_daily_hits(user_id)

async def can_hit(user_id: int) -> tuple:
    if await is_admin(user_id):
        return True, None
    plan = await get_user_plan(user_id)
    if plan["unlimited"]:
        if plan["hits_per_day"] > 0:
            hits = await get_daily_hits(user_id)
            if hits >= plan["hits_per_day"]:
                return False, f"Daily limit reached ({plan['hits_per_day']}/day). Contact owner for upgrade!"
        return True, None
    hits = await get_daily_hits(user_id)
    remaining = FREE_DAILY_LIMIT - hits
    if remaining <= 0:
        return False, f"Daily limit reached ({FREE_DAILY_LIMIT}/day on Free plan). Contact owner for access!"
    return True, None

# ─── Logging ───

async def log_check(user_id: int, card: str, url: str, merchant: str, amount: str, status: str, response: str, time_taken: float):
    db = await get_db()
    await db.execute("""
        INSERT INTO check_logs (user_id, card, checkout_url, merchant, amount, status, response, time_taken, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, (user_id, card, url[:100], merchant or "", amount or "", status, response or "", time_taken, datetime.now(timezone.utc).isoformat()))
    await db.commit()

async def get_user_logs(user_id: int, limit: int = 20) -> list:
    db = await get_db()
    async with db.execute("SELECT * FROM check_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT ?", (user_id, limit)) as cursor:
        rows = await cursor.fetchall()
        return [dict(r) for r in rows]

async def get_recent_charged_hits(limit: int = 20) -> list:
    db = await get_db()
    async with db.execute("SELECT * FROM check_logs WHERE status = 'CHARGED' ORDER BY timestamp DESC LIMIT ?", (limit,)) as cursor:
        rows = await cursor.fetchall()
        return [dict(r) for r in rows]

async def get_user_hit_stats(user_id: int) -> dict:
    db = await get_db()
    async with db.execute("SELECT COUNT(*) as total FROM check_logs WHERE user_id = ?", (user_id,)) as c:
        total = (await c.fetchone())['total']
    async with db.execute("SELECT COUNT(*) as charged FROM check_logs WHERE user_id = ? AND status = 'CHARGED'", (user_id,)) as c:
        charged = (await c.fetchone())['charged']
    async with db.execute("SELECT COUNT(*) as live FROM check_logs WHERE user_id = ? AND status = 'LIVE'", (user_id,)) as c:
        live = (await c.fetchone())['live']
    async with db.execute("SELECT COUNT(*) as declined FROM check_logs WHERE user_id = ? AND status = 'DECLINED'", (user_id,)) as c:
        declined = (await c.fetchone())['declined']
    return {"total": total, "charged": charged, "live": live, "declined": declined}

# ─── Proxies ───

async def add_proxy(user_id: int, proxy: str):
    db = await get_db()
    try:
        await db.execute("INSERT OR REPLACE INTO proxies (user_id, proxy) VALUES (?, ?)", (user_id, proxy))
        await db.commit()
        return True
    except:
        return False

async def remove_proxy(user_id: int, proxy: str = None):
    db = await get_db()
    if proxy and proxy.lower() != "all":
        await db.execute("DELETE FROM proxies WHERE user_id = ? AND proxy = ?", (user_id, proxy))
    else:
        await db.execute("DELETE FROM proxies WHERE user_id = ?", (user_id,))
    await db.commit()

async def get_proxies(user_id: int) -> list:
    db = await get_db()
    async with db.execute("SELECT proxy FROM proxies WHERE user_id = ?", (user_id,)) as cursor:
        rows = await cursor.fetchall()
        return [r['proxy'] for r in rows]

# ─── Ranking ───

async def get_charged_ranking(limit: int = 10) -> list:
    db = await get_db()
    query = """
        SELECT cl.user_id, COUNT(*) as charged_count, u.username, u.first_name
        FROM check_logs cl
        LEFT JOIN users u ON cl.user_id = u.user_id
        WHERE cl.status = 'CHARGED'
        GROUP BY cl.user_id
        ORDER BY charged_count DESC
        LIMIT ?
    """
    async with db.execute(query, (limit,)) as cursor:
        rows = await cursor.fetchall()
        return [dict(r) for r in rows]

# ─── Saved BINs ───

async def save_bin(user_id: int, name: str, bin_value: str) -> bool:
    db = await get_db()
    try:
        await db.execute("""
            INSERT OR REPLACE INTO saved_bins (user_id, name, bin_value, created_at)
            VALUES (?, ?, ?, ?)
        """, (user_id, name.lower(), bin_value, datetime.now(timezone.utc).isoformat()))
        await db.commit()
        return True
    except:
        return False

async def get_saved_bins(user_id: int) -> list:
    db = await get_db()
    async with db.execute("SELECT name, bin_value FROM saved_bins WHERE user_id = ? ORDER BY created_at DESC LIMIT 50", (user_id,)) as cursor:
        rows = await cursor.fetchall()
        return [dict(r) for r in rows]

async def delete_saved_bin(user_id: int, name: str) -> bool:
    db = await get_db()
    await db.execute("DELETE FROM saved_bins WHERE user_id = ? AND name = ?", (user_id, name.lower()))
    await db.commit()
    return True

# ─── Redeem codes ───

async def create_redeem_code(plan_type: str, days: int, hits_per_day: int, max_uses: int, created_by: int) -> str:
    chars = string.ascii_uppercase + string.digits
    code = "-".join("".join(secrets.choice(chars) for _ in range(4)) for _ in range(3))
    db = await get_db()
    await db.execute("""
        INSERT INTO redeem_codes (code, plan_type, days, hits_per_day, max_uses, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    """, (code, plan_type, days, hits_per_day, max_uses, created_by, datetime.now(timezone.utc).isoformat()))
    await db.commit()
    return code

async def use_redeem_code(user_id: int, code: str) -> dict:
    code = code.upper().strip()
    db = await get_db()
    async with db.execute("SELECT * FROM redeem_codes WHERE code = ? AND is_active = 1", (code,)) as cursor:
        row = await cursor.fetchone()
        if not row:
            return {"success": False, "error": "Invalid or expired code"}
        if row['used_count'] >= row['max_uses']:
            return {"success": False, "error": "Code already fully used"}
        
        async with db.execute("SELECT 1 FROM code_uses WHERE code = ? AND user_id = ?", (code, user_id)) as c:
            if await c.fetchone():
                return {"success": False, "error": "You already used this code"}
        
        await db.execute("INSERT INTO code_uses (code, user_id, used_at) VALUES (?, ?, ?)", (code, user_id, datetime.now(timezone.utc).isoformat()))
        await db.execute("UPDATE redeem_codes SET used_count = used_count + 1 WHERE code = ?", (code,))
        
        if row['used_count'] + 1 >= row['max_uses']:
            await db.execute("UPDATE redeem_codes SET is_active = 0 WHERE code = ?", (code,))
        
        hpd = row['hits_per_day'] or 0
        await set_user_plan(user_id, row['plan_type'], row['days'], hpd)
        await db.commit()
        return {"success": True, "plan_type": row['plan_type'], "days": row['days'], "hits_per_day": hpd}

async def get_active_codes() -> list:
    db = await get_db()
    async with db.execute("SELECT * FROM redeem_codes WHERE is_active = 1 ORDER BY created_at DESC LIMIT 20") as cursor:
        rows = await cursor.fetchall()
        return [dict(r) for r in rows]

# ─── Stats ───

async def get_global_stats() -> dict:
    db = await get_db()
    async with db.execute("SELECT COUNT(*) as c FROM users WHERE is_banned = 0") as c: users = (await c.fetchone())['c']
    async with db.execute("SELECT COUNT(*) as c FROM check_logs") as c: checks = (await c.fetchone())['c']
    async with db.execute("SELECT COUNT(*) as c FROM check_logs WHERE status = 'CHARGED'") as c: charged = (await c.fetchone())['c']
    async with db.execute("SELECT COUNT(*) as c FROM check_logs WHERE status = 'LIVE'") as c: live = (await c.fetchone())['c']
    async with db.execute("SELECT COUNT(*) as c FROM users WHERE is_banned = 1") as c: banned = (await c.fetchone())['c']
    async with db.execute("SELECT COUNT(*) as c FROM redeem_codes WHERE is_active = 1") as c: active_codes = (await c.fetchone())['c']
    return {"users": users, "checks": checks, "charged": charged, "live": live, "banned": banned, "active_codes": active_codes}

async def get_all_user_ids() -> list:
    db = await get_db()
    async with db.execute("SELECT user_id FROM users WHERE is_banned = 0") as cursor:
        rows = await cursor.fetchall()
        return [r['user_id'] for r in rows]

async def get_setting(key: str, default=None):
    db = await get_db()
    async with db.execute("SELECT value FROM bot_settings WHERE key = ?", (key,)) as cursor:
        row = await cursor.fetchone()
        return row['value'] if row else default

async def set_setting(key: str, value: str):
    db = await get_db()
    await db.execute("INSERT OR REPLACE INTO bot_settings (key, value) VALUES (?, ?)", (key, value))
    await db.commit()

async def close():
    global _db_connection
    if _db_connection:
        await _db_connection.close()
        _db_connection = None

# ─── Admin role ───

async def add_admin(user_id: int):
    db = await get_db()
    await db.execute("INSERT OR REPLACE INTO admins (user_id) VALUES (?)", (user_id,))
    await db.commit()

async def remove_admin(user_id: int):
    db = await get_db()
    await db.execute("DELETE FROM admins WHERE user_id = ?", (user_id,))
    await db.commit()

async def is_admin(user_id: int) -> bool:
    db = await get_db()
    async with db.execute("SELECT 1 FROM admins WHERE user_id = ?", (user_id,)) as cursor:
        return bool(await cursor.fetchone())

async def get_all_admins() -> list:
    db = await get_db()
    async with db.execute("SELECT user_id FROM admins") as cursor:
        rows = await cursor.fetchall()
        return [r['user_id'] for r in rows]
