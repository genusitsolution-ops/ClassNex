"""
ClassNex UI smoke test (headless Chrome via Playwright).
Logs in as each role, opens every screen, fails on JavaScript errors, saves screenshots.

  python3 tests/ui_smoke.py http://127.0.0.1:8081 /tmp/shots
Requires the data created by tests/e2e.php (owner 9876543210 / Owner@123 etc.).
"""
import sys, os, json
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8081"
OUT = sys.argv[2] if len(sys.argv) > 2 else "/tmp/shots"
os.makedirs(OUT, exist_ok=True)
CHROME = "/usr/bin/google-chrome" if os.path.exists("/usr/bin/google-chrome") else None

USERS = {
    "owner": ("9876543210", "Owner@123", "class-admin", [
        "#/", "#/students", "#/students/new", "#/students/1", "#/students/1?tab=fees", "#/attendance", "#/attendance/1", "#/attendance-history",
        "#/collect?student=1", "#/fees", "#/payments", "#/messages", "#/message-log", "#/activities", "#/batches", "#/timetable",
        "#/teachers", "#/parents", "#/admissions", "#/fee-plans", "#/announcements", "#/holidays", "#/leaves", "#/reports",
        "#/reports?r=pending_fees", "#/subscription", "#/settings", "#/settings?tab=notify", "#/settings?tab=receipts",
        "#/settings?tab=admission", "#/settings?tab=payments", "#/branches", "#/branches/1", "#/reports?r=branch_summary", "#/students?branch_id=1", "#/attendance-history?view=students", "#/attendance-history?view=branches", "#/whatsapp-log", "#/settings?tab=audit",
        "#/support", "#/notifications", "#/profile"]),
    "teacher": ("9123456780", "Teach@123", "teacher", ["#/", "#/attendance", "#/attendance-history", "#/batches", "#/students", "#/timetable", "#/announcements", "#/leaves", "#/support"]),
    "parent": ("9876511111", "Parent@123", "parent", ["#/", "#/attendance", "#/fees", "#/timetable", "#/announcements", "#/holidays", "#/notifications", "#/support"]),
    "admin": ("qa-admin@classnex.test", "Admin@12345", "admin", [
        "#/", "#/registrations", "#/registrations?status=rejected", "#/owners", "#/owners/1", "#/packages", "#/sub-payments", "#/integrations", "#/integrations?tab=firebase", "#/integrations?tab=email",
        "#/integrations?tab=sms", "#/integrations?tab=razorpay", "#/integrations?tab=ocr", "#/templates", "#/reminders", "#/queue", "#/cms",
        "#/cms?tab=about", "#/cms?tab=seo", "#/cms?tab=faq", "#/cms?tab=testimonials", "#/cms?tab=pages", "#/enquiries", "#/support",
        "#/reports", "#/audit", "#/settings", "#/health"]),
}
SITE = ["index.php", "features.php", "solutions.php", "pricing.php", "how-it-works.php", "about.php", "contact.php", "support.php",
        "page.php?p=privacy-policy", "login.php", "register.php", "admission.php?a=abc-cricket-academy", "install.php"]

failures = []
# one branch for the branch screens (the e2e data set is single-location)
import subprocess
subprocess.run(["mysql", "-u" + os.environ.get("CNX_DB_USER", "classnex"), "-p" + os.environ.get("CNX_DB_PASS", "classnex"), os.environ.get("CNX_DB", "classnex_test"), "-e",
                "INSERT IGNORE INTO academy_branches (id, academy_id, name, address, status) VALUES (1, 1, 'Main Ground', 'MG Road', 'active'); UPDATE batches SET branch_id = 1 WHERE academy_id = 1 AND branch_id IS NULL; UPDATE students SET branch_id = 1 WHERE academy_id = 1 AND branch_id IS NULL;"], check=False)
shots = {"owner": ["#/", "#/branches", "#/branches/1", "#/students/new", "#/attendance/1", "#/collect?student=1", "#/students/1?tab=fees", "#/settings?tab=notify"],
         "teacher": ["#/"], "parent": ["#/", "#/fees"], "admin": ["#/", "#/integrations", "#/owners/1"]}

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=CHROME, args=["--no-sandbox"])
    # ---------- public website
    ctx = browser.new_context(viewport={"width": 1366, "height": 900})
    page = ctx.new_page()
    errs = []
    page.on("pageerror", lambda e: errs.append(str(e)))
    page.on("console", lambda m: m.type == "error" and errs.append(m.text))
    for url in SITE:
        errs.clear()
        r = page.goto(f"{BASE}/{url}", wait_until="networkidle")
        bad = [e for e in errs if "favicon" not in e]
        status = r.status if r else 0
        ok = status == 200 and not bad
        print(("PASS" if ok else "FAIL"), "site", url, status, bad[:2])
        if not ok:
            failures.append((url, status, bad))
        if url in ("index.php", "pricing.php", "login.php", "register.php"):
            page.screenshot(path=f"{OUT}/site_{url.split('.')[0]}.png", full_page=url == "index.php")
    mobile = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True)
    mp = mobile.new_page(); mp.goto(f"{BASE}/index.php", wait_until="networkidle"); mp.screenshot(path=f"{OUT}/site_home_mobile.png")
    ctx.close()

    # ---------- panels
    for role, (ident, pw, panel, routes) in USERS.items():
        ctx = browser.new_context(viewport={"width": 1366, "height": 900})
        page = ctx.new_page()
        errs = []
        page.on("pageerror", lambda e: errs.append("pageerror: " + str(e)))
        page.on("console", lambda m: m.type == "error" and errs.append("console: " + m.text))
        page.goto(f"{BASE}/login.php", wait_until="networkidle")
        page.fill("[name=identifier]", ident)
        page.fill("[name=password]", pw)
        page.click("#lf [type=submit]")
        page.wait_for_url(f"**/{panel}/**", timeout=15000)
        page.wait_for_load_state("networkidle")
        for rt in routes:
            errs.clear()
            page.goto(f"{BASE}/{panel}/{rt}")
            page.wait_for_load_state("networkidle")
            page.wait_for_timeout(350)
            alert = page.locator("#view .alert-danger").all_inner_texts()
            bad = [e for e in errs if "favicon" not in e and "tessdata" not in e and "403" not in e]
            ok = not bad and not alert
            print(("PASS" if ok else "FAIL"), role, rt, (bad + alert)[:3])
            if not ok:
                failures.append((role, rt, bad + alert))
            if rt in shots.get(role, []):
                page.screenshot(path=f"{OUT}/{role}_{rt.strip('#/').replace('/', '_').replace('?', '_').replace('=', '-') or 'dashboard'}.png", full_page=True)
        # mobile responsiveness: every screen of every role at phone width, no sideways scrolling of the page
        m = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, storage_state=ctx.storage_state())
        mp = m.new_page()
        merrs = []
        mp.on("pageerror", lambda e: merrs.append(str(e)))
        for rt in routes:
            merrs.clear()
            mp.goto(f"{BASE}/{panel}/{rt}"); mp.wait_for_load_state("networkidle"); mp.wait_for_timeout(250)
            over = mp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
            okm = over <= 2 and not merrs
            print(("PASS" if okm else "FAIL"), "mobile", role, rt, f"overflow={over}px", merrs[:1])
            if not okm:
                failures.append(("mobile " + role, rt, [f"page wider than the screen by {over}px"] + merrs[:1]))
            if role == "owner" and rt in ["#/", "#/attendance/1", "#/students/new", "#/branches", "#/attendance-history"]:
                mp.screenshot(path=f"{OUT}/mobile_owner_{rt.strip('#/').replace('/', '_') or 'dashboard'}.png", full_page=True)
        m.close()
        ctx.close()
    browser.close()

print(f"\n{len(failures)} failure(s)")
print(json.dumps(failures, indent=1)[:4000])
sys.exit(1 if failures else 0)
