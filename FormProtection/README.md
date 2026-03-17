# Devexa SmartShield — Invisible Form Protection

## Overview
Invisible bot protection for Magento 2 forms. No CAPTCHAs needed. Analyzes user behavior (typing speed, mouse movement, focus events) to detect bots and block spam submissions.

## Features
- **Invisible** — No CAPTCHA, no puzzles, no friction for real users
- **Behavior analysis** — Typing speed, mouse movement, focus events, time on page, paste detection
- **IP & country blocking** — Block specific IPs (exact, CIDR, wildcard) and countries
- **Challenge modes** — Delay countdown, checkbox, or math question for suspicious users
- **Auto-blacklist** — Repeated offenders are automatically blocked
- **Two modes**: Local (analyze) or SaaS (API via Devexa Platform)
- **Exclude forms** — Skip protection on specific forms (e.g., cart, search)
- **Translatable** — Badge text and messages translated via Magento i18n (6 languages included)
- **AJAX interception** — Catches both native form submits AND fetch/XMLHttpRequest
- **Hyva & Luma** compatible

## How It Works

### Analyze Mode (Free, local)
1. SmartShield JS loaded from Magento static files
2. Behavior tracked client-side (typing, mouse, timing)
3. On form submit → validates against local Magento controller
4. Controller applies configured rules (IP, country, behavior thresholds)
5. Returns: allow / challenge / block

### API Mode (Paid, SaaS)
1. SmartShield JS loaded from Devexa Platform (`ai.devexa.ro/js/smartshield.js`)
2. Behavior tracked client-side
3. On form submit → validates against Platform API
4. Platform checks: IP blacklist, country rules, behavior analysis, shared threat data
5. Returns: allow / challenge / block
6. Results logged in Platform dashboard

## Protection Layers
1. **Strip form action** — Forms can't submit without validation
2. **Submit event intercept** — Catches native form.submit()
3. **fetch() intercept** — Catches AJAX submissions via fetch()
4. **XMLHttpRequest intercept** — Catches jQuery $.ajax() calls
5. **Action restored only on pass** — Re-stripped after 2 seconds

## Risk Scoring
| Signal | Score | Why |
|--------|-------|-----|
| Blocked country | +50 | Country in blocklist |
| Blocked IP | +40 | IP/CIDR/wildcard match |
| No mouse movement | +30 | Bots don't move mouse |
| No focus events | +25 | Bots don't focus fields |
| Typing too fast (<20ms) | +20 | Bots type instantly |
| Time on page too short | +15 | Bots submit immediately |
| Paste detected | +10 | Spam indicator |

Score >= 50 → Challenge, Score >= 80 → Block (configurable)

## Configuration
**Stores > Config > Devexa > SmartShield Form Protection**

- General: Enable, Mode (Analyze/API)
- Protected Forms: Login, Register, Contact, Checkout, Newsletter, Review + custom selectors
- Exclude Forms: CSS selectors for forms to skip (e.g., cart forms)
- Protection Rules: Blocked countries/IPs, thresholds
- Appearance: Show/hide badge, badge text, block message, challenge type

## Translations Included
en_US, ro_RO, es_ES, de_DE, it_IT, nb_NO

## Requirements
- Devexa_Core module (license)
- For API mode: Active SmartShield subscription on Devexa Platform
- Magento 2.4+, PHP 8.1+
