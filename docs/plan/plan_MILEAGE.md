# Plan MILEAGE: правило по пробегу в решении по заявке

Сверен со специкацией `docs/spec/spec_MILEAGE.md` — покрытие всех REQ и AC
в §5. Закрывает OQ-1, перенесённый из intent в план (intent §4): имя поля
ответа — `review_reason`, значение кода причины — `mileage_over_threshold`
(пример из интервью, `docs/intent/grill_MILEAGE.md:30-31`).

Назначение файла: план реализации задачи MILEAGE по конвенции репозитория
(`docs/plan/README.md`) — предполагаемое место в репозитории
`docs/plan/plan_MILEAGE.md`.

## 1. Файлы

Меняем только перечисленное. Всё, чего нет в списке, при реализации
трогать нельзя.

| Файл | Что делаем |
|---|---|
| `backend/config/rules.php` | новый раздел `mileage` с ключом `downgrade_threshold_km` = 400000 (REQ-MILEAGE-06) |
| `backend/src/Domain/MileageRule.php` | новый `final MileageRule` — даунгрейд approve → review и код причины |
| `backend/src/Domain/AssessmentService.php` | применить `MileageRule` после LTV-решения, добавить `review_reason` в результат |
| `backend/src/AppFactory.php` | связать `MileageRule` с `AssessmentService` из `rules['mileage']` |
| `backend/src/Http/ApplicationController.php` | добавить `review_reason` в JSON-ответы POST /api/ltv и POST /api/applications |
| `tests/Unit/MileageRuleTest.php` | новый тест: границы 399999/400000/400001, даунгрейд только approve, порог из конфига |
| `tests/Unit/AssessmentServiceTest.php` | дополнить: правило на уровне сервиса, `review_reason`, `approved_limit` = 0 |
| `tests/Unit/ApplicationValidatorTest.php` | дополнить: 400001…500000 валидны, 500001 нет, пустой/отсутствующий/null пробег |

Не трогаем: `db/schema.sql`, `db/seed.sql`, `frontend/`,
`backend/src/Repository/ApplicationRepository.php` (`review_reason` в БД не
пишем — правки БД вне scope), `backend/src/Domain/DecisionEngine.php`,
`backend/src/Domain/ApplicationValidator.php`,
`backend/src/Domain/LtvCalculator.php`, `backend/public/*`, маршруты.

## 2. Шаги

1. `backend/config/rules.php`: после раздела `ltv` добавить раздел `mileage`:
   `['downgrade_threshold_km' => 400000]` с комментарием, что это правило
   уровня решения и что валидационный `max_mileage_km` = 500 000
   (`rules.php:23`) не меняется.
2. Новый `backend/src/Domain/MileageRule.php`, сигнатура:

   ```php
   final class MileageRule
   {
       public const REASON_OVER_THRESHOLD = 'mileage_over_threshold';

       /** @param array{downgrade_threshold_km:int} $config */
       public function __construct(private readonly array $config)
       {
       }

       /**
        * @return array{decision:string, review_reason:?string}
        */
       public function apply(string $decision, int $mileage): array
       {
       }
   }
   ```

   Логика `apply`: если `$decision === DecisionEngine::APPROVE` и `$mileage >
   downgrade_threshold_km` — вернуть `[DecisionEngine::REVIEW,
   self::REASON_OVER_THRESHOLD]`; иначе — `[$decision, null]`. Решения review
   и reject не меняются (REQ-MILEAGE-02).
3. `backend/src/Domain/AssessmentService.php`: внедрить `MileageRule` через
   конструктор; в `assess()` после `$this->decisionEngine->decide($ltv)`
   вызвать `$mileageRule->apply($decision, $input['mileage'])`; итоговое
   решение использовать и для `approved_limit` — существующая строка
   `AssessmentService.php:39` после даунгрейда вернёт 0 (REQ-MILEAGE-07);
   добавить в ответ ключ `review_reason` и расширить PHPDoc `@return` полем
   `review_reason:?string`.
4. `backend/src/AppFactory.php`: в `create()` передать
   `new MileageRule($rules['mileage'])` в `AssessmentService`.
5. `backend/src/Http/ApplicationController.php`: в ответы `create()` (201) и
   `ltv()` добавить `'review_reason' => $result['review_reason']`. Поле есть
   всегда: без даунгрейда оно `null` (REQ-MILEAGE-05); `Json::write`
   сериализует его как `"review_reason":null`, поле не пропадает.
6. Тесты из §3, затем `make test` и `make lint`.

## 3. Тесты

PHPUnit, AAA, имя метода описывает поведение. Граничные значения — отдельными
строками: **399999 / 400000 / 400001** (порог правила), **500000 / 500001**
(лимит валидации), **пустой** (поле отсутствует) и **неизвестный** (`null`)
пробег.

`tests/Unit/MileageRuleTest.php` (новый):

- 399999 км + approve → approve, `review_reason` null (AC-MILEAGE-03, AC-MILEAGE-13)
- 400000 км + approve → approve, `review_reason` null — граница включительная (AC-MILEAGE-02)
- 400001 км + approve → review, `review_reason` = `mileage_over_threshold` (AC-MILEAGE-01, AC-MILEAGE-11)
- 400001 км + reject → reject, `review_reason` null (AC-MILEAGE-04, AC-MILEAGE-14)
- 400001 км + review → review, `review_reason` null (AC-MILEAGE-05, AC-MILEAGE-12)
- порог из конфига: `MileageRule` с другим `downgrade_threshold_km` срабатывает по нему, код не менялся (AC-MILEAGE-15)

`tests/Unit/AssessmentServiceTest.php` (дополнить; в хелпере `payload()`
пробег 96000 — переопределять `mileage`):

- LTV-approve + mileage 400001 → decision review, `review_reason` = `mileage_over_threshold`, `approved_limit` 0 (AC-MILEAGE-01, AC-MILEAGE-11, AC-MILEAGE-16)
- LTV-approve + mileage 400000 → approve, `review_reason` null (AC-MILEAGE-02, AC-MILEAGE-13)
- LTV-approve + mileage 399999 → approve, `review_reason` null (AC-MILEAGE-03)
- LTV-reject + mileage 400001 → reject, `review_reason` null (AC-MILEAGE-04, AC-MILEAGE-14)
- LTV-review + mileage 400001 → review, `review_reason` null (AC-MILEAGE-05, AC-MILEAGE-12)

`tests/Unit/ApplicationValidatorTest.php` (дополнить — валидация не изменилась):

- mileage 400001 → проходит валидацию (AC-MILEAGE-06)
- mileage 500000 → проходит валидацию (AC-MILEAGE-07)
- mileage 500001 → `ValidationException` (AC-MILEAGE-08)
- поле `mileage` отсутствует (пустой пробег) → `ValidationException` с ключом `mileage` (AC-MILEAGE-09)
- `mileage` = null (неизвестный пробег) → `ValidationException` (AC-MILEAGE-10)

HTTP-уровень (`ApplicationController`) отдельно не тестируем: он без
логики передаёт поля результата; все AC проверяются на домене и сервисе.
Feature-тесты не заводим.

## 4. Риски

- Валидационный лимит `max_mileage_km` = 500 000 (`rules.php:23`,
  `ApplicationValidator.php:43-46`) не меняем. Риск — случайно занести порог
  400 000 в валидатор; защищают тесты «400001…500000 валидны, 500001 нет».
- `DecisionEngine` и `DecisionEngineTest` не меняем: правило — постобработка
  его решения, LTV-пороги (`ltv.approve_max` / `ltv.review_max`) не трогаем.
- Формат ответа: только добавляем ключ `review_reason` — существующие ассерты
  по ключам не ломаются. Поле присутствует всегда (null без даунгрейда), иначе
  потребитель не отличит null от отсутствия поля.
- `ApplicationRepository::save()` получает весь `$result`, а его PHPDoc уже
  сегодня уже фактических ключей (туда приходят `vehicle_age` и `input`) —
  runtime это не ломает; `review_reason` в БД не пишем, PHPDoc репозитория не
  чиним: БД и репозиторий вне scope (intent §5).
- `error_log` в контроллере не дописываем: причина даунгрейда в логе спекой
  не требуется.
- phpunit запущен с `failOnWarning` и `failOnRisky`: в новых тестах следить
  за типами (int для пробега и лимита, `?string` для причины) и не оставлять
  тестов без assert'ов.
- Frontend, БД и LOAN-12 вне scope (intent §5) — в diff попасть не должны.

## 5. Сверка со спекой

| Спека | Где в плане |
|---|---|
| REQ-MILEAGE-01 | шаг 2; MileageRuleTest (границы), AssessmentServiceTest |
| REQ-MILEAGE-02 | шаг 2 (условие approve); MileageRuleTest reject/review |
| REQ-MILEAGE-03 | валидатор не меняется (в §1 он в списке «не трогаем»); ApplicationValidatorTest 400001/500000/500001 |
| REQ-MILEAGE-04 | код валидации не меняется; ApplicationValidatorTest (отсутствует/null) |
| REQ-MILEAGE-05 | шаги 2, 3, 5; тесты причины filled/null |
| REQ-MILEAGE-06 | шаги 1, 2, 4; тест «порог из конфига» |
| REQ-MILEAGE-07 | шаг 3 (существующая строка `AssessmentService.php:39`); тест `approved_limit` = 0 |

AC-MILEAGE-01…16 покрыты тестами из §3 — номера AC указаны у каждого теста.

## 6. Вопросы, на которые без заказчика не ответить

Нет. Единственный открытый вопрос intent — OQ-1 (имя поля и значение кода
причины) — этот план закрывает, взяв пример из интервью: `review_reason` /
`mileage_over_threshold` (intent §4: «закроет plan»).
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
