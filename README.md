# Работа+ — Freelance Platform

Платформа за наемане на изпълнители за кратки услуги в България.

## Инсталация

### Изисквания
- XAMPP (PHP 8.0+, MySQL/MariaDB)
- Apache с mod_rewrite

### Стъпки

1. **Копирай папката** `freelance-platform/` в `C:\xampp\htdocs\`

2. **Импортирай базата данни:**
   - Отвори phpMyAdmin: http://localhost/phpmyadmin
   - Кликни "Импортирай" (Import)
   - Избери файла `freelance-platform/database.sql`
   - Кликни "Изпълни"

3. **(По желание) Промени настройките на базата данни** в `config/db.php`:
   ```php
   define('DB_USER', 'root');  // твоят MySQL потребител
   define('DB_PASS', '');      // твоята MySQL парола
   ```

4. **Отвори в браузъра:** http://localhost/freelance-platform/

---

## Демо акаунти

| Имейл | Парола | Роля |
|-------|--------|------|
| admin@platform.bg | password | Администратор |
| ivan@example.bg | password | Потребител |
| maria@example.bg | password | Потребител |
| petar@example.bg | password | Потребител |

---

## Функции

- 🏠 **Начална страница** — свободни обяви с търсене и филтри
- 📋 **Обяви** — публикуване, преглед, кандидатстване
- 💬 **Чат** — автоматично отваряне при кандидатура, real-time polling
- 📄 **Договори** — ескроу система, потвърждения, история
- ⚠️ **Спорове** — отваряне на спор, решаване от администратор
- 👤 **Профил** — редакция, снимка, баланс, транзакции
- ⚙️ **Администрация** — управление на спорове и статистика

---

## Структура на файловете

```
freelance-platform/
├── config/db.php           # Конфигурация на базата данни
├── includes/
│   ├── functions.php       # Помощни функции
│   ├── header.php          # Хедър шаблон
│   └── footer.php          # Футър шаблон
├── assets/
│   ├── style.css           # Стилове
│   └── uploads/            # Качени снимки
├── auth/
│   ├── login.php
│   ├── register.php
│   └── logout.php
├── listings/
│   ├── create.php
│   └── view.php
├── messages/
│   ├── index.php           # Списък чатове
│   └── chat.php            # Чат страница
├── contracts/
│   ├── index.php
│   └── view.php
├── profile/
│   ├── edit.php
│   └── deposit.php
├── admin/
│   └── disputes.php
├── api/
│   └── poll.php            # Real-time polling
└── database.sql
```
