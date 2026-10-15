# Changelog

Все значимые изменения проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
проект придерживается [Semantic Versioning](https://semver.org/lang/ru/).

---

## [Unreleased]

### Added
- Планируется: автоблокировка по частоте запросов (rate limit)
- Планируется: автоподхват IP из access-логов
- Планируется: поддержка wildcard в User-Agent (`*bot*`)

---

## [1.0.0] — 2026-10-15

### Added
- 🎉 Первый релиз плагина Bot Blocker
- Блокировка по IP-адресу
- Блокировка по CIDR-диапазону (например, `192.168.0.0/24`)
- Блокировка по User-Agent
- Блокировка по Referer
- Белый список (whitelist) для своих IP и доверенных ботов
- Лог срабатываний с фильтрами
- Пресеты правил:
  - Известные сканеры уязвимостей
  - SEO-краулеры (Semrush, Ahrefs, MJ12, DotBot)
  - Сканеры чужих CMS (WordPress, Joomla, DLE)
- Импорт правил из CSV и JSON
- Экспорт правил в CSV и JSON
- Настройка действия при блокировке: 403 / 404 / редирект
- Автоматический белый список для IP администратора
- Автоочистка логов (настраиваемый срок хранения)

### Security
- Валидация всех правил перед сохранением
- Экранирование IP и User-Agent от инъекций
- Не логируются чувствительные данные
- Защита от path traversal при импорте

---

## Ссылки

- [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/)
- [Semantic Versioning](https://semver.org/lang/ru/)
- [Репозиторий на GitHub](https://github.com/festacms/festacms-plugin-bot-blocker)

[Unreleased]: https://github.com/festacms/festacms-plugin-bot-blocker/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/festacms/festacms-plugin-bot-blocker/releases/tag/v1.0.0