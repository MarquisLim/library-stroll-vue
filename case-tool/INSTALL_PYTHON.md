# Установка Python для CASE-инструмента

## Требования
- Python 3.8 или выше (рекомендуется Python 3.11 или 3.12)

## Установка на Windows

### Вариант 1: Официальный установщик (рекомендуется)

1. **Скачайте Python:**
   - Перейдите на https://www.python.org/downloads/
   - Скачайте последнюю версию Python 3.11 или 3.12 для Windows

2. **Установка:**
   - Запустите установщик
   - ✅ **ВАЖНО:** Отметьте галочку "Add Python to PATH" (добавить Python в PATH)
   - Нажмите "Install Now"

3. **Проверка установки:**
   Откройте PowerShell или командную строку и выполните:
   ```powershell
   python --version
   ```
   Должно показать: `Python 3.11.x` или `Python 3.12.x`

### Вариант 2: Через Microsoft Store (проще)

1. Откройте Microsoft Store
2. Найдите "Python 3.11" или "Python 3.12"
3. Нажмите "Установить"
4. Готово! Python автоматически добавится в PATH

## Проверка работы CASE-инструмента

После установки Python:

1. **Откройте PowerShell** в папке проекта:
   ```powershell
   cd c:\OSPanel\domains\library-stroll-vue\case-tool
   ```

2. **Проверьте Python:**
   ```powershell
   python --version
   ```

3. **Запустите валидацию модели:**
   ```powershell
   python main.py validate models/library_stroll.json
   ```

4. **Создайте диаграмму:**
   ```powershell
   python main.py visualize models/library_stroll.json diagram.dot
   ```

## Если Python не найден

Если команда `python` не работает, попробуйте:
- `py --version` (Python Launcher для Windows)
- `python3 --version`

Или добавьте Python в PATH вручную:
1. Найдите где установлен Python (обычно `C:\Users\ВашеИмя\AppData\Local\Programs\Python\Python311\`)
2. Добавьте эту папку в переменную окружения PATH

## Дополнительные инструменты (опционально)

Для визуализации DOT-файлов в PNG/SVG:

1. **Установите Graphviz:**
   - Скачайте с https://graphviz.org/download/
   - Или через Chocolatey: `choco install graphviz`
   - Или через winget: `winget install graphviz`

2. **После установки Graphviz:**
   ```powershell
   dot -Tsvg diagram.dot -o diagram.svg
   dot -Tpng diagram.dot -o diagram.png
   ```

## Быстрая проверка

Выполните в PowerShell:
```powershell
cd c:\OSPanel\domains\library-stroll-vue\case-tool
python main.py validate models/library_stroll.json
```

Если все работает, вы увидите:
```
Загрузка модели из models/library_stroll.json...
✓ Модель 'Library Stroll Domain Model' загружена (10 сущностей)

Валидация модели...
✓ Модель валидна!
```

