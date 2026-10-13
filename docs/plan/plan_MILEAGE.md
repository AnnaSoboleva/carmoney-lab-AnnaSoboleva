# План: правило «пробег ≤ 400 000 км, ин��че review»

Задача: в расчёте решения по заявке добавить правило уровня решения (не валидации): при `mileage > 400000` решение даунгрейдится до `review`. Валидационный лимит остаётся 500 000 (`max_mileage_km` в `rules.php:23`) — пробег 400 001…500 000 валиден, но решение = `review`.

Контекст: `docs/setup/code_map.md` (полный разбор конвейера) + разведка scout (см. `docs/setup/agents.md`).

## 1. Файлы — что и гд�� меняем

- `backend/config/rules.php:23` — в секцию `vehicle` добавить ключ `mileage_review_above_km` со значением `400000`. Это бизнес-число, держим его в конфиге, а не в коде.
- `backend/src/Domain/DecisionEngine.php:20-28` — в конструктор добавить свойство `private readonly int $mileageReviewAbove` (и параметр в массиве порогов). Расширить PHPDoc типа `$thresholds`.
- `backend/src/Domain/DecisionEngine.php:30-41` — сигнатура `decide(float $ltv)` → `decide(float $ltv, int $mileage)`. В конце метода (после LTV-цепочки, перед `return self::REJECT` на 40-й и блоком `APPROVE`) добавить проверку: `if ($mileage > $this->mileageReviewAbove && $decision === self::APPROVE) { $decision = self::REVIEW; }`. Выбор «только даунгрейд `approve → review`, `reject` оставляем» — см. вопрос Q1.
- `backend/src/AppFactory.php:37` — `new DecisionEngine($rules['ltv'])` → `new DecisionEngine(['approve_max' => $rules['ltv']['approve_max'], 'review_max' => $rules['ltv']['review_max'], 'mileage_review_above_km' => $rules['vehicle']['mileage_review_above_km']])`. Так не разъезжается формат массива, который ждёт существующий код.
- `backend/src/Domain/AssessmentService.php:33` — `$this->decisionEngine->decide($ltv)` → `$this->decisionEngine->decide($ltv, $input['mileage'])`. Поле уже валидировано и в `int` (см. `ApplicationValidator.php:78`).
- `tests/Unit/AssessmentServiceTest.php:33-43` — хелпер `payload()` оста��ить как есть (`mileage => 96000` — попадает в зону «ok»). Добавить отдельные тест-кейсы (��м. раздел 3).
- `tests/Unit/ApplicationValidatorTest.php` — НЕ трогаем: валидационный лимит пробега остаётся 500 000, и существующие тесты валида��ора (на диапазон 0…500000) должны пройти без правок. Это явная проверка обратной с��вместимости (см. риск R2).

## 2. Шаги реализации по порядку

1. **Конфиг.** В `backend/config/rules.php` в массиве `vehicle` (строка 20-24) добавить `'mileage_review_above_km' => 400000`.
2. **Конструктор `DecisionEngine`.** Расшир��ть `$thresholds` PHPDoc до `{approve_max:float, review_max:float, mileage_review_above_km:int}`, добавить свойство и инициализацию из `$thresholds['mileage_review_above_km']`.
3. **Метод `decide()`.** Поменять сигнатуру на `decide(float $ltv, int $mileage): string`. Внутри вычислить `$decision` по существующей LTV-цепочке, затем применить пробежную проверку (даунгрейд `APPROVE → REVIEW` при `mileage > порога`), вернуть финальное значение.
4. **Сборка `AppFactory`.** На строке 37 передать в `DecisionEngine` массив с тремя ключами: `approve_max`, `review_max` (из `$rules['ltv']`) и `mileage_review_above_km` (из `$rules['vehicle']`).
5. **Точка вызова `AssessmentService`.** На строке 33 передать вторым аргументом `$input['mileage']`.
6. **Тесты.** Добавить в `tests/Unit/AssessmentServiceTest.php` 4 кейса (см. раздел 3). Запустить `make test` и `make lint`.
7. **Самопроверка.** Прогнать `make test` — все ранее зелёные тесты остаются зелёными (особенно `ApplicationValidatorTest` на 500 000).

## 3. Тесты

Все в `tests/Unit/AssessmentServiceTest.php`, структура AAA, имена методов описывают поведение, в конце `assert*`. Б��зовый payload из существующего `payload($amount, $marketValue)` подменяем полем `mileage` через локальную к��пию массива, чтобы не плодить хелперы.

Параметры подобраны так, чтобы LTV был низким (`amount=450000, market_value=900000 → ltv=50.0 → approve`) и не маскировал пробежный даунгрейд. Случай «пустой пробег» отдельно: при отсутствии поля mileage валидатор (`ApplicationValidator.php:43`) выдаст `-1` и бросит `ValidationException` — это и проверяем.

| # | Имя теста | Вход (mileage) | Ожидани�� |
|---|-----------|----------------|----------|
| 1 | `testApprovesWhenMileageJustBelowReviewThreshold` | `399999` | `decision = APPROVE`, `approved_limit = 450000`, `ltv = 50.0` |
| 2 | `testApprovesWhenMileageExactlyAtReviewThreshold` | `400000` | `decision = APPROVE` (граница «≤», см. Q1) |
| 3 | `testDowngradesApproveToReviewWhenMileageJustAboveReviewThreshold` | `400001` | `decision = REVIEW`, `approved_limit = 0`, `ltv = 50.0` |
| 4 | `testRejectsValidationWhenMileageIsMissingFromPayload` | ключ `mileage` отсутствует | `expectException(ValidationException::class)` с полем `errors['mileage']` |

Дополнительно (необязательно, но дёшево): кейс 5 — `testKeepsRejectWhenHighLtvAndHighMileage` (mileage=400001, amount=855000, market=900000 → ltv=95.0): решение остаётся `REJECT`, не превращается в `REVIEW`. Это страховка от «принудительного review» вместо «даунгрейда только approve». Стоит добавить, если ответ на Q1 — «только даунгрейд approve».

## 4. Риск��

- **R1. Семантика «иначе review» не определена в коде.** `code_map.md:48` явно фиксирует: неясно, даунгрейдить только `approve → review` или принудительно ставить `review` поверх любого решения. Выбор «только даунгрейд `approve`» более консервативен и не ухудшает `reject`; обратный вариант требует подтверждения заказчика. Тест-кейс 5 (см. раздел 3) закрывает это поведенчески, но до ответа на Q1 тест может быть временным.
- **R2. Конфликт с существующей проверкой `max_mileage_km = 500000`.** Валидационный лимит остаётся 500 000 (`ApplicationValidator.php:44`), новый поро�� 400 000 лежит внутри валидной зоны. Если кто-то по ошибке поднимет `max_mileage_km` ниже 400 000 — пробежная ветка станет недостижимой. Защита: не трогаем `max_mileage_km` и не валидируем пересечение ключей в `rules.php` (это вне задачи). В комментарии к `mileage_review_above_km` в `rules.php` стоит явно отметить, что значение должно быть `≤ max_mileage_km`.
- **R3. Изменение сигнатуры `DecisionEngine::decide()`** — это публичный API класса. Сейчас `decide` вызывается ровно в одном месте (`AssessmentService.php:33`), так что точечно безопасно. Если позже появятся другие вызовы (например, в `ApplicationController` для отдельной ручки «только LTV-решение»), их придётся обновить. Текущий поиск: прямых вызовов `->decide(` кроме `AssessmentService` нет.
- **R4. Дрейф комментариев в `DecisionEngine`.** Уже сейчас в докблоке `DecisionEngine.php:10-12` написано «`LTV <= approve_max → approve`», хотя в коде строгое `<` (см. `code_map.md:26`). Если в Q1 заказчик попросит «`≤ 400 000`», граничный кейс 2 (`mileage = 400000`) опирается на ту ж�� логику «строгое `>`». Не путать с расхождением LTV-порогов; это разные правила.
- **R5. Существующие фикстуры с маленьким пробегом** (`AssessmentServiceTest.php:38` — 96000, `ApplicationValidatorTest.php:34` — 84000) автоматически попадают в зону «ok» и не должны сломаться. Это проверяем прогоном `make test` после правки.
- **R6. Фронтенд и БД не задеты.** Поле ввода пробега не ограничено (`frontend/index.html:31` — без `max`), диапазон валидации бэкенда не меняется, колонка `mileage_km` в БД не меняется. Доп. правок не требуется.

## Чего не входит в эту задачу

- Снижение валидационного лимита `max_mileage_km` (остаётся 500 000).
- Изменение семантики LTV-порогов (`<` vs `≤` на `approve_max`).
- Расчёт лимита п�� возрасту авто (задача LOAN-12, см. `code_map.md:27`).
- Изменения фронтенда, схемы БД, сидов.

## Вопросы заказчику (без ответов план не блокируется, но они меняют поведение)

- **Q1. «Иначе review» — это даунгрейд или принудительное решение?**
  - (а) Только даунгрейд `approve → review` при `mileage > 400000`; если LTV уже дал `reject`, остаётся `reject` (предлагаемый вариант — консервативный, не ухудшает плохое).
  - (б) Принудительное `review` при `mileage > 400000` поверх любого LTV-решения (т.е. `reject` тоже превращается в `review`).
  Тест-кейс 5 в разделе 3 зависит от ответа.
- **Q2. Граница включительно или нет?** Условие «пробег не больше 400 000» в задаче ��аписано буквально — это `mileage ≤ 400000 → ok`, `mileage > 400000 → review`. То есть `mileage = 400000` остаётся `approve` (если LTV ок). Именно этот вариант зашит в кейс 2. Подтвердить.
- **Q3. Ну��ен ли новый ключ конфига или хардкод 400000 в коде?** Текущий план — ключ `vehicle.mileage_review_above_km` в `rules.php` (соответствует конвенции из AGENTS.md, п. 4). Если заказчик хочет «магическое число» в коде — убрать ключ и параметр конструктора.
