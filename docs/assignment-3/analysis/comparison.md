# Сравнение до и после трансформации

## Описание

Сравнение DFD модели и полученной UML модели для процесса "Управление работами".

## До трансформации (DFD)

### Структура процессов

```
Уровень 1:
2.0 Управление работами
  ├── Входы: Файлы работ, метаданные
  ├── Выходы: Опубликованные работы
  └── Хранилища: База данных, Файловое хранилище

Уровень 2 (элементарные процессы):
  2.1 Валидация файла
  2.2 Обработка изображения
  2.3 Сохранение в хранилище
  2.4 Создание записи в БД
  2.5 Получение работы
  2.6 Обновление работы
  2.7 Удаление работы
```

### Потоки данных

- Файл → 2.1 → Валидный файл → 2.2 → Обработанный файл → 2.3 → Путь к файлу
- Путь к файлу + Метаданные → 2.4 → ID работы
- ID работы → 2.5 → Данные работы + Файл

### Хранилища данных

- База данных (artworks)
- Файловое хранилище

## После трансформации (UML)

### Структура классов

```java
class ArtworkService {
    - FileValidator validator
    - ImageProcessor processor
    - StorageService storage
    - ArtworkRepository repository
    
    + uploadArtwork(file: File, metadata: ArtworkData): Artwork
    + getArtwork(id: int): Artwork
    + updateArtwork(id: int, data: ArtworkData): Artwork
    + deleteArtwork(id: int): void
}

class FileValidator {
    + validate(file: File): ValidationResult
}

class ImageProcessor {
    + process(image: Image): ProcessedImage
    + resize(image: Image, width: int, height: int): Image
    + createThumbnail(image: Image): Image
}

class StorageService {
    + save(file: File, path: String): String
    + get(filePath: String): File
    + delete(filePath: String): void
}

class ArtworkRepository {
    + create(data: ArtworkData): Artwork
    + findById(id: int): Artwork
    + update(id: int, data: ArtworkData): Artwork
    + delete(id: int): void
}
```

### Диаграмма последовательности

```
User → ArtworkService: uploadArtwork(file, metadata)
ArtworkService → FileValidator: validate(file)
FileValidator → ArtworkService: ValidationResult
ArtworkService → ImageProcessor: process(image)
ImageProcessor → ArtworkService: ProcessedImage
ArtworkService → StorageService: save(file, path)
StorageService → ArtworkService: filePath
ArtworkService → ArtworkRepository: create(data)
ArtworkRepository → ArtworkService: Artwork
ArtworkService → User: Artwork
```

## Сравнительная таблица

| Аспект | DFD | UML | Соответствие |
|--------|-----|-----|--------------|
| **Процесс 2.1** | Процесс "Валидация файла" | Класс `FileValidator` с методом `validate()` | ✅ Полное |
| **Процесс 2.2** | Процесс "Обработка изображения" | Класс `ImageProcessor` с методом `process()` | ✅ Полное |
| **Процесс 2.3** | Процесс "Сохранение в хранилище" | Класс `StorageService` с методом `save()` | ✅ Полное |
| **Процесс 2.4** | Процесс "Создание записи в БД" | Метод `ArtworkRepository.create()` | ✅ Полное |
| **Поток "Файл"** | Поток данных | Параметр `file: File` | ✅ Полное |
| **Поток "ID работы"** | Поток данных | Возвращаемое значение `int` | ✅ Полное |
| **Хранилище "База данных"** | Хранилище данных | Класс `ArtworkRepository` | ✅ Полное |
| **Хранилище "Файловое хранилище"** | Хранилище данных | Класс `StorageService` | ✅ Полное |
| **Последовательность 2.1→2.2→2.3→2.4** | Цепочка процессов | Sequence diagram с вызовами методов | ✅ Полное |
| **Условие "Файл валиден?"** | Условие в DFD | Guard `[file.isValid()]` в Activity | ⚠️ Частичное |
| **Обработка ошибок** | Неявная в DFD | Exception handling в UML | ❌ Добавлено |

## Что изменилось

### Добавлено в UML

1. **Типы данных:** Конкретные типы (File, Image, Artwork) вместо абстрактных потоков
2. **Обработка ошибок:** Exception handling для невалидных данных
3. **Инкапсуляция:** Данные и методы объединены в классы
4. **Видимость:** Модификаторы доступа (public, private)
5. **Ассоциации:** Явные связи между классами

### Упрощено в UML

1. **Потоки данных:** Преобразованы в параметры и возвраты методов
2. **Хранилища:** Абстрагированы в Repository классы
3. **Внешние сущности:** Преобразованы в актеры или зависимости

### Сохранено

1. **Последовательность процессов:** Сохранена в sequence diagrams
2. **Логика обработки:** Сохранена в методах классов
3. **Структура данных:** Сохранена через классы моделей

## Преимущества трансформации

1. ✅ **Детализация:** UML добавляет типы данных и структуру
2. ✅ **Реализуемость:** UML ближе к коду, легче реализовать
3. ✅ **Инкапсуляция:** Объединение данных и методов
4. ✅ **Расширяемость:** Наследование и полиморфизм

## Недостатки трансформации

1. ❌ **Потеря функционального взгляда:** DFD лучше показывает потоки данных
2. ❌ **Усложнение:** UML модель более детальная и сложная
3. ❌ **Дублирование:** Необходимо поддерживать обе модели

## Выводы

Трансформация DFD → UML успешно сохраняет основную структуру и логику процессов, добавляя детали реализации. Гибридный подход позволяет использовать преимущества обеих методологий.

