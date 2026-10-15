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
