# Spec MILEAGE: правило по пробегу в решении по заявке

Источник всех требований — `docs/intent/intent_MILEAGE.md` (§3 Constraints).
Соответствие REQ пунктам intent — в §6; требований сверх intent в спеке нет.

## 1. Входит / не входит

### Входит

- Правило по пробегу на уровне решения: при пробеге больше 400 000 км решение
  approve понижается до review; review и reject по LTV не меняются
  (REQ-MILEAGE-01, REQ-MILEAGE-02).
- Пробег 400 001…500 000 км — валидный вход: правило не меняет валидацию
  (REQ-MILEAGE-03).
- Поле `mileage` обязательно (REQ-MILEAGE-04).
- Новое поле ответа API — код причины даунгрейда: заполняется только при
  понижении из-за пробега, иначе null (REQ-MILEAGE-05). Точное имя поля и
  значение кода спека не фиксирует — открытый вопрос intent перенесён в
  plan (§4).
- Порог 400 000 км хранится в `backend/config/rules.php` (REQ-MILEAGE-06).
- `approved_limit` при даунгрейде — 0 (REQ-MILEAGE-07).

### Не входит

- Изменение валидационного лимита `max_mileage_km` = 500 000
  (`backend/config/rules.php:23`) — intent §5, `docs/intent/grill_MILEAGE.md:20-23`.
- Правки БД (`db/schema.sql`, `db/seed.sql`) и `frontend/` — intent §5,
  `docs/intent/grill_MILEAGE.md:24-26`.
- LOAN-12: расчёт `approved_limit` по `ltv_by_age`
  (`backend/src/Domain/AssessmentService.php:10-12`) — intent §5,
  `docs/intent/grill_MILEAGE.md:24-26`.
- Универсальный `reason` с кодами для всех решений, включая LTV — intent §5,
  `docs/intent/grill_MILEAGE.md:27-31`.

## 2. Требования

### REQ-MILEAGE-01 — порог срабатывания

Правило по пробегу срабатывает только при пробеге заявки больше 400 000 км
(400 001 и выше); при пробеге 400 000 км и меньше правило не срабатывает
(граница включительная).

Источник: «не больше 400 000 км» — формулировка задачи
(`docs/intent/grill_MILEAGE.md:8-9`); граничные значения 399999 / 400000 /
400001 — `docs/plan/README.md:13`.

### REQ-MILEAGE-02 — даунгрейд, не перекрытие

При срабатывании правила решение понижается только с approve до review:
LTV-reject остаётся reject, LTV-review остаётся review.

Источник: `docs/intent/grill_MILEAGE.md:16-19` (intent §3, п. 2).

### REQ-MILEAGE-03 — правило уровня решения, не валидации

Пробег 400 001…500 000 км — валидный вход: заявка с таким пробегом проходит
валидацию и доходит до расчёта решения.

Источник: intent §3, п. 3; проверка пробега —
`backend/src/Domain/ApplicationValidator.php:43-46`; лимит `max_mileage_km`
= 500 000 — `backend/config/rules.php:23`.

### REQ-MILEAGE-04 — пробег обязателен

Поле `mileage` в заявке обязательно: если поле отсутствует или равно null,
заявка не проходит валидацию (ValidationException) и до правила решения
не доходит.

Источник: intent §3, п. 4; `docs/intent/grill_MILEAGE.md:10-12`;
`backend/src/Domain/ApplicationValidator.php:43-46`.

### REQ-MILEAGE-05 — код причины даунгрейда в ответе

В ответе API появляется поле с кодом причины даунгрейда: оно заполняется
только когда решение понижено с approve до review из-за пробега; во всех
остальных случаях — null. Точное имя поля и значение кода спека не фиксирует
(открытый вопрос intent, перенесён в plan, §4).

Источник: `docs/intent/grill_MILEAGE.md:27-31` (intent §3, п. 5).

### REQ-MILEAGE-06 — порог в конфиге

Порог 400 000 км хранится в `backend/config/rules.php`; код читает порог из
конфига и не содержит это число в себе.

Источник: intent §3, п. 6; паттерн «бизнес-числа в конфиге» —
`backend/config/rules.php`.

### REQ-MILEAGE-07 — approved_limit при даунгрейде

При понижении решения с approve до review из-за пробега `approved_limit`
в ответе — 0.

Источник: intent §3, п. 7; `backend/src/Domain/AssessmentService.php:39`.

## 3. Критерии приёмки

Значения решений approve / review / reject —
`backend/src/Domain/DecisionEngine.php:16-18`. «Решение по LTV» — решение,
рассчитанное действующими порогами LTV (`backend/config/rules.php:43-46`),
до применения правила по пробегу.

- **AC-MILEAGE-01** (REQ-MILEAGE-01, REQ-MILEAGE-02) — граница 400 001.
  Given заявка, решение которой по LTV — approve, и пробег 400 001 км.
  When заявка оценивается.
  Then decision = review — правило сработало.
- **AC-MILEAGE-02** (REQ-MILEAGE-01) — граница 400 000, ровно на пороге.
  Given заявка с решением approve по LTV и пробегом 400 000 км.
  When заявка оценивается.
  Then decision = approve — правило не сработало (граница включительная).
- **AC-MILEAGE-03** (REQ-MILEAGE-01) — граница 399 999, сразу до порога.
  Given заявка с решением approve по LTV и пробегом 399 999 км.
  When заявка оценивается.
  Then decision = approve — правило не сработало.
- **AC-MILEAGE-04** (REQ-MILEAGE-02) — reject не перекрывается.
  Given заявка с решением reject по LTV и пробегом 400 001 км.
  When заявка оценивается.
  Then decision = reject.
- **AC-MILEAGE-05** (REQ-MILEAGE-02) — review не меняется.
  Given заявка с решением review по LTV и пробегом 400 001 км.
  When заявка оценивается.
  Then decision = review.
- **AC-MILEAGE-06** (REQ-MILEAGE-03) — нижняя граница валидного входа.
  Given заявка с пробегом 400 001 км.
  When заявка валидируется.
  Then ValidationException нет — заявка доходит до расчёта решения.
- **AC-MILEAGE-07** (REQ-MILEAGE-03) — верхняя граница валидного входа.
  Given заявка с пробегом 500 000 км.
  When заявка валидируется.
  Then ValidationException нет.
- **AC-MILEAGE-08** (REQ-MILEAGE-03) — сразу за валидационным лимитом.
  Given заявка с пробегом 500 001 км.
  When заявка валидируется.
  Then ValidationException — лимит `max_mileage_km` = 500 000
  (`backend/config/rules.php:23`) не изменился.
- **AC-MILEAGE-09** (REQ-MILEAGE-04) — пустой пробег.
  Given заявка без поля `mileage`.
  When заявка валидируется.
  Then ValidationException; решение не вычисляется, правило по пробегу
  не применяется.
- **AC-MILEAGE-10** (REQ-MILEAGE-04) — неизвестный пробег.
  Given заявка с `mileage` = null.
  When заявка валидируется.
  Then ValidationException; решение не вычисляется.
- **AC-MILEAGE-11** (REQ-MILEAGE-05) — код при даунгрейде.
  Given решение понижено с approve до review из-за пробега (AC-MILEAGE-01).
  When формируется ответ API.
  Then поле кода причины не null и содержит код даунгрейда по пробегу
  (точное имя поля и значение кода — из plan, §4; пример из интервью:
  `mileage_over_threshold`).
- **AC-MILEAGE-12** (REQ-MILEAGE-05) — null, когда понижения не было: LTV-review.
  Given заявка с решением review по LTV и пробегом 400 001 км — понижения
  не было.
  When формируется ответ API.
  Then поле кода причины = null.
- **AC-MILEAGE-13** (REQ-MILEAGE-05) — null при approve.
  Given заявка с решением approve по LTV и пробегом 400 000 км.
  When формируется ответ API.
  Then поле кода причины = null.
- **AC-MILEAGE-14** (REQ-MILEAGE-05) — null при reject.
  Given заявка с решением reject по LTV при любом валидном пробеге.
  When формируется ответ API.
  Then поле кода причины = null.
- **AC-MILEAGE-15** (REQ-MILEAGE-06) — порог из конфига.
  Given порог 400 000 км задан в `backend/config/rules.php`.
  When в конфиге порог временно изменён на другое значение и заявка
  оценивается.
  Then правило срабатывает по значению из конфига, правки кода нет.
- **AC-MILEAGE-16** (REQ-MILEAGE-07) — лимит при даунгрейде.
  Given решение понижено с approve до review из-за пробега.
  When формируется ответ API.
  Then `approved_limit` = 0.

## 4. Open questions из intent и статус

- **OQ-1.** Точное имя поля и значение кода причины (пример из интервью:
  `review_reason` / `mileage_over_threshold`) — intent §4.
  **Статус: перенесён** в `docs/plan/plan_MILEAGE.md` (файл плана задачи
  MILEAGE по `docs/plan/README.md:6`; intent: «закроет plan»). Спека имя поля
  и значение кода не фиксирует: REQ-MILEAGE-05 и AC-MILEAGE-11…14 проверяют
  заполнение/null безотносительно имени.

## 5. Числа и их источники

| Число | Смысл | Источник |
|---|---|---|
| 400 000 км | порог правила по пробегу, граница включительная | формулировка задачи — `docs/intent/grill_MILEAGE.md:8`; подтверждено граничными тестами — `docs/plan/README.md:13` |
| 399 999 / 400 000 / 400 001 км | граничные значения порога | `docs/plan/README.md:13`; intent §3, п. 1 |
| 500 000 км | валидационный лимит `max_mileage_km`, не меняется | `backend/config/rules.php:23`; `backend/src/Domain/ApplicationValidator.php:44` |
| 500 001 км | сразу за валидационным лимитом — невалидный вход | `backend/src/Domain/ApplicationValidator.php:44` |
| 0 | `approved_limit` при даунгрейде, рублей | `backend/src/Domain/AssessmentService.php:39` |
| null | код причины, когда даунгрейда по пробегу не было | `docs/intent/grill_MILEAGE.md:29-31` |

## 6. Соответствие intent

Каждый REQ происходит из intent §3 (Constraints), по пунктам:
REQ-MILEAGE-01 ← п. 1, REQ-MILEAGE-02 ← п. 2, REQ-MILEAGE-03 ← п. 3,
REQ-MILEAGE-04 ← п. 4, REQ-MILEAGE-05 ← п. 5, REQ-MILEAGE-06 ← п. 6,
REQ-MILEAGE-07 ← п. 7. Раздел «Не входит» (§1) — из intent §5.
Единственный открытый вопрос intent (§4) перенесён в plan (§4 этой спеки).
Требований, которых не было в intent, в спеке нет.