# План: тесты на границы пробега 399999/400000/400001 и пустой пробег

## Результат
Никаких изменений не требуется. Запрошенные тесты уже существуют и покрывают все указанные сценарии.

## Существующее покрытие

### Границы пробега 399999 / 400000 / 400001
- `tests/Unit/MileageRuleTest.php:39-47` — data provider `mileageCases`:
  - `399999 км, approve` → остаётся `approve`
  - `400000 км, approve` → остаётся `approve` (включительная граница)
  - `400001 км, approve` → даунгрейд до `review` с причиной `mileage_over_threshold`
- `tests/Unit/AssessmentServiceTest.php:85-100` — три сквозных теста на те же значения через `AssessmentService`:
  - `testKeepsApproveBelowMileageThreshold` (399999)
  - `testKeepsApproveAtMileageThreshold` (400000)
  - `testDowngradesApproveToReviewWhenMileageOverThreshold` (400001)
- `tests/Unit/AssessmentServiceTest.php:102-118` — `testKeepsRejectWhenMileageOverThreshold` и `testKeepsReviewWhenMileageOverThreshold` подтверждают, что `reject`/`review` не меняются правилом.

### Пустой пробег
- `tests/Unit/ApplicationValidatorTest.php:107-118` — `testRejectsMissingMileage` (ключ `mileage` отсутствует через `unset`).
- `tests/Unit/ApplicationValidatorTest.php:120-128` — `testRejectsNullMileage` (`'mileage' => null`).

Оба кейса приводят к `(int) ($payload['mileage'] ?? -1) === -1`, что ловится проверкой `mileage < 0` и бросает `ValidationException` с ключом `mileage`.

## Что НЕ входит в план
- Кейс пустой строки `''` сознательно не добавлен: пользователь подтвердил, что текущего покрытия достаточно. Текущее поведение валидатора — `(int) '' === 0`, проходит проверку — остаётся как есть.
- Изменения исходного кода и конфигов не требуются.

## Валидация
Ничего запускать не нужно — изменений нет.
