# Activity диаграмма - Процесс комментирования

## Описание

Диаграмма активности показывает бизнес-процесс добавления комментария к работе.

## Диаграмма (Mermaid)

```mermaid
flowchart TD
    Start([Пользователь открывает<br/>работу]) --> ViewComments[Просмотреть<br/>существующие комментарии]
    ViewComments --> WriteComment[Написать комментарий]
    
    WriteComment --> CheckParent{Это ответ<br/>на комментарий?}
    CheckParent -->|Да| SelectParent[Выбрать родительский<br/>комментарий]
    CheckParent -->|Нет| ValidateComment{Комментарий<br/>валиден?}
    
    SelectParent --> ValidateComment
    
    ValidateComment -->|Нет| ErrorComment[Ошибка:<br/>Комментарий пустой<br/>или слишком длинный]
    ErrorComment --> WriteComment
    
    ValidateComment -->|Да| CheckModeration{Требуется<br/>модерация?}
    CheckModeration -->|Да| SavePending[Сохранить как<br/>ожидающий модерации]
    CheckModeration -->|Нет| SaveComment[Сохранить комментарий<br/>в БД]
    
    SavePending --> NotifyModerator[Уведомить модератора]
    NotifyModerator --> WaitModeration{Модератор<br/>одобрил?}
    WaitModeration -->|Нет| RejectComment[Отклонить комментарий]
    WaitModeration -->|Да| SaveComment
    
    SaveComment --> NotifyAuthor[Уведомить автора работы<br/>о новом комментарии]
    NotifyAuthor --> NotifyParent{Есть родительский<br/>комментарий?}
    NotifyParent -->|Да| NotifyParentAuthor[Уведомить автора<br/>родительского комментария]
    NotifyParent -->|Нет| Success([Комментарий<br/>добавлен])
    NotifyParentAuthor --> Success
    
    RejectComment --> End([Конец])
    
    style Start fill:#e1f5ff
    style Success fill:#c8e6c9
    style ErrorComment fill:#ffebee
    style RejectComment fill:#ffebee
    style SaveComment fill:#fff3e0
    style NotifyAuthor fill:#f3e5f5
```

## Описание процесса

### Этап 1: Просмотр и написание
1. Просмотр существующих комментариев
2. Написание нового комментария
3. Определение, является ли комментарий ответом

### Этап 2: Валидация
1. Проверка валидности комментария (не пустой, не слишком длинный)
2. Проверка необходимости модерации

### Этап 3: Сохранение
1. Сохранение комментария в БД
2. Если требуется модерация - сохранение как ожидающий одобрения

### Этап 4: Уведомления
1. Уведомление автора работы о новом комментарии
2. Если это ответ - уведомление автора родительского комментария

## Альтернативные потоки

- **Невалидный комментарий** → возврат к написанию
- **Требуется модерация** → ожидание одобрения модератором
- **Комментарий отклонен** → уведомление пользователя об отклонении

