# UML Диаграммы активностей

## Описание

Диаграммы активностей, полученные трансформацией процессов DFD.

## Диаграмма активности: Загрузка работы

```mermaid
flowchart TD
    Start([Начало: Загрузка работы]) --> Validate[Валидация файла]
    Validate --> Check{Файл валиден?}
    
    Check -->|Нет| Error[Ошибка валидации]
    Error --> End([Конец: Ошибка])
    
    Check -->|Да| Process[Обработка изображения]
    Process --> Resize[Изменение размера]
    Resize --> Thumbnail[Создание миниатюры]
    Thumbnail --> SaveFile[Сохранение файла]
    
    SaveFile --> CreateDB[Создание записи в БД]
    CreateDB --> Success[Успешная загрузка]
    Success --> EndSuccess([Конец: Успех])
    
    style Validate fill:#e1f5ff
    style Process fill:#fff3e0
    style SaveFile fill:#f3e5f5
    style CreateDB fill:#e8f5e9
    style Error fill:#ffebee
    style Success fill:#c8e6c9
```

## Диаграмма активности: Получение работы

```mermaid
flowchart TD
    Start([Начало: Получение работы]) --> GetDB[Получение данных из БД]
    GetDB --> Check{Работа найдена?}
    
    Check -->|Нет| NotFound[Работа не найдена]
    NotFound --> EndError([Конец: Ошибка])
    
    Check -->|Да| GetFile[Получение файла из хранилища]
    GetFile --> Combine[Объединение данных]
    Combine --> Return[Возврат работы]
    Return --> End([Конец: Успех])
    
    style GetDB fill:#e1f5ff
    style GetFile fill:#fff3e0
    style Combine fill:#f3e5f5
```

## Диаграмма активности: Обновление работы

```mermaid
flowchart TD
    Start([Начало: Обновление]) --> GetCurrent[Получение текущих данных]
    GetCurrent --> ValidateData{Данные валидны?}
    
    ValidateData -->|Нет| Invalid[Невалидные данные]
    Invalid --> EndError([Конец: Ошибка])
    
    ValidateData -->|Да| UpdateDB[Обновление в БД]
    UpdateDB --> Success[Успешное обновление]
    Success --> End([Конец: Успех])
    
    style GetCurrent fill:#e1f5ff
    style UpdateDB fill:#fff3e0
```

## Диаграмма активности: Удаление работы

```mermaid
flowchart TD
    Start([Начало: Удаление]) --> GetData[Получение данных работы]
    GetData --> Check{Работа существует?}
    
    Check -->|Нет| NotFound[Работа не найдена]
    NotFound --> EndError([Конец: Ошибка])
    
    Check -->|Да| DeleteFile[Удаление файла]
    DeleteFile --> DeleteDB[Удаление из БД]
    DeleteDB --> Success[Успешное удаление]
    Success --> End([Конец: Успех])
    
    style GetData fill:#e1f5ff
    style DeleteFile fill:#fff3e0
    style DeleteDB fill:#f3e5f5
```

## Соответствие DFD процессам

- **Загрузка работы** соответствует процессам: 2.1 → 2.2 → 2.3 → 2.4
- **Получение работы** соответствует процессу: 2.5
- **Обновление работы** соответствует процессу: 2.6
- **Удаление работы** соответствует процессу: 2.7

