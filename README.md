# Free Period Share Bot

A Persian/English Telegram bot for menstrual-cycle awareness, PMS and ovulation estimates, late-period reminders, and optional cycle sharing with a partner.

## Use the Bot

Use **Free Period Share** on Telegram:

👉 [@Free_Period_Share_bot](https://t.me/Free_Period_Share_bot)

The bot helps users track:

- Menstrual cycle
- PMS
- Estimated ovulation
- Late-period reminders
- Cycle information sharing with a partner

The bot is completely free to use.

**No Premium plan. No paid features. No subscription required.**

---

## About the Creator

I am **Farshad Mosaffa**, the creator of Free Period Share.

I built this project with the goal of providing people with free access to a simple tool for better menstrual-cycle awareness, without requiring any payment or subscription.

My main professional activity is **portfolio and investment management in the stock and cryptocurrency markets**. I am also involved in a legal-services startup that provides legal consultation and attorney services.

### Contact

For portfolio management and investment-related inquiries:

👉 [@farshdamosaffa](https://t.me/farshdamosaffa)

For legal consultation and attorney services:

👉 [@Online_vakil_24_bot](https://t.me/Online_vakil_24_bot)

Personal website:

🌐 [farshadmosaffa.ir](https://farshadmosaffa.ir/)

---

<div dir="rtl">

## فارسی

ربات **Free Period Share** یک ابزار رایگان برای پیگیری چرخه قاعدگی، PMS، زمان تقریبی تخمک‌گذاری، یادآوری تأخیر پریود و اشتراک اطلاعات چرخه با پارتنر است.

### استفاده از ربات

برای استفاده از ربات در تلگرام:

👉 [@Free_Period_Share_bot](https://t.me/Free_Period_Share_bot)

این ربات به شما کمک می‌کند:

- چرخه قاعدگی خود را پیگیری کنید
- زمان تقریبی PMS را مشاهده کنید
- زمان تقریبی تخمک‌گذاری را محاسبه کنید
- تأخیر در پریود را پیگیری کنید
- اطلاعات چرخه را در صورت تمایل با پارتنر خود به اشتراک بگذارید

استفاده از ربات کاملاً رایگان است.

**هیچ نسخه Premium، قابلیت پولی یا اشتراک اجباری وجود ندارد.**

---

## درباره سازنده

من **فرشاد مصفا**، سازنده Free Period Share هستم.

این ربات را با هدف دسترسی رایگان مردم به یک ابزار ساده برای آگاهی بیشتر درباره چرخه قاعدگی ساخته‌ام؛ بدون نیاز به پرداخت هزینه یا خرید اشتراک.

فعالیت اصلی من در حوزه **سبدگردانی و مدیریت سرمایه در بازار بورس و ارزهای دیجیتال** است. در کنار آن، در حوزه خدمات حقوقی نیز فعالیت دارم و یک مجموعه برای ارائه مشاوره حقوقی و خدمات وکالت راه‌اندازی کرده‌ام.

### راه‌های ارتباطی

برای همکاری در زمینه سبدگردانی و مدیریت سرمایه:

👉 [@farshdamosaffa](https://t.me/farshdamosaffa)

برای استفاده از خدمات وکیل و مشاور حقوقی:

👉 [@Online_vakil_24_bot](https://t.me/Online_vakil_24_bot)

وب‌سایت شخصی من:

🌐 [farshadmosaffa.ir](https://farshadmosaffa.ir/)

</div>

## Main behavior

- Language is selected before gender.
- A female account can track cycle length, bleeding duration, estimated ovulation day, PMS lead time, and tap “period started”.
- A partner account can connect to a lady, and two lady accounts can connect to each other while tracking their own cycles independently.
- Partner-role accounts do not store personal cycle dates or cycle/bleeding/ovulation/PMS values; lady-role accounts receive defaults when registration begins.
- A partner can generate a one-day invite code/deep link for a lady or enter a code shared by her. A new lady joining through a partner's link finishes her own cycle registration before the connection is made.
- Partner connection is optional and uses a cryptographically random 8-digit, Luhn-checked, single-use code. Codes expire after 24 hours.
- Connection attempts are rate-limited. Telegram updates are idempotent.
- PMS, ovulation, late-period, early-period, and period-start messages are tailored separately for the woman and her partner.
- Creator and “why free” buttons remain in the persistent keyboard.
- The anonymous Notes area is available to every bot user. Everyone reads the same latest 10 messages; cycle-owner notes use the “Lady message” label and every other partner uses the gender-neutral “Partner message” label. Notes survive profile deletion, block contact details and media, escape HTML, and are rate-limited.
- Connected users can disconnect from Settings after a confirmation step. Both sides are unlinked atomically and notified in their selected language; cycle data, notification history, and anonymous notes are preserved.
- Recording a period start switches the lady’s keyboard action to “My period ended”; ending it stores the end date and notifies a connected partner without changing the cycle start anchor.
- A recent start date entered during onboarding also activates the end button when it falls within the user's usual bleeding duration. The period-start action is offered only on the persistent bottom keyboard, not in the welcome or due-date message.
- The persistent keyboard offers the last five recorded cycles. Each entry shows the actual interval to the next recorded start and the actual inclusive bleeding duration when an end date is known; unavailable values are labelled unknown instead of guessed.
- Telegram buttons use a silent one-second database-backed debounce, while in-bot Back actions remain exempt.

## Security and deployment

- `config.php` stays outside the web document root.
- Telegram webhooks require Telegram's secret-token header.
- Cron and setup endpoints require independent high-entropy secrets.
- Pairing codes are stored as keyed hashes, never as plaintext.
- `setup.php` creates the schema and deletes itself only after success.
- `migrations/007_partner_invites.sql` makes cycle fields nullable and clears only legacy partner-role cycle values; it does not alter lady-role data.
- Scheduled notifications isolate failures per user and remove the deduplication marker when primary delivery fails, so one blocked chat cannot stop other users and transient failures remain retryable.
- The production cron runs `cron-cli.php` daily at server time 09:15 with the verified `/usr/local/bin/php` binary, avoiding DNS/HTTPS outages in the public domain. Its output goes to a private, owner-only log.
- Directory listing and public access to bootstrap files are disabled.
- The public endpoint is deployed as a real directory and does not create WordPress posts, pages, or custom content.

The default estimates are a 28-day cycle, 5 bleeding days, ovulation around day 14, and PMS beginning about 5 days before the next estimated period. Every value is editable. Start dates older than 60 days are accepted, but scheduled PMS, ovulation, due-date, and late reminders remain disabled until a new period start is recorded.

Persian dates accept both `1405/10/20` and `20/10/1405`; English dates accept both `2026-09-20` and `20-09-2026`. The middle component is always the month.
