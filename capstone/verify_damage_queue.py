import time
from playwright.sync_api import sync_playwright

def verify_ui():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 800})
        page = context.new_page()
        
        # Bypass login and go straight to delivery damage queue
        page.goto("http://localhost/VIP-system/capstone/bypass_login.php")
        
        # Wait for table to render
        page.wait_for_timeout(2000)
        
        # Take full screenshot
        page.screenshot(path="delivery_queue_verified_centered.png", full_page=True)
        print("Screenshot saved to delivery_queue_verified_centered.png")
        
verify_ui()
