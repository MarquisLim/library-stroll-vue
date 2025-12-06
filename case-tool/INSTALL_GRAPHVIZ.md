# Установка Graphviz для визуализации диаграмм

## Вариант 1: Установка через установщик (рекомендуется)

1. **Скачайте Graphviz:**
   - Перейдите на https://graphviz.org/download/
   - Выберите "Windows" → "Stable Windows installers"
   - Скачайте `.msi` файл (например, `graphviz-9.0.0 (64-bit) Windows 10+ installer.msi`)

2. **Установка:**
   - Запустите установщик
   - ✅ **ВАЖНО:** При установке выберите опцию "Add Graphviz to the system PATH for all users" или "Add Graphviz to the system PATH for current user"
   - Завершите установку

3. **Проверка:**
   - Закройте и снова откройте PowerShell/CMD
   - Выполните:
     ```powershell
     dot -V
     ```
   - Должно показать версию Graphviz

4. **Использование:**
   ```powershell
   cd c:\OSPanel\domains\library-stroll-vue\case-tool
   dot -Tsvg diagram.dot -o diagram.svg
   dot -Tpng diagram.dot -o diagram.png
   ```

## Вариант 2: Установка через Chocolatey

Если у вас установлен Chocolatey:
```powershell
choco install graphviz
```

## Вариант 3: Установка через winget

Если у вас Windows 10/11 с winget:
```powershell
winget install graphviz
```

## Вариант 4: Онлайн конвертация (без установки)

Если не хотите устанавливать Graphviz, можно использовать онлайн-сервисы:

1. **Edotor.net:**
   - Откройте https://edotor.net/
   - Скопируйте содержимое `diagram.dot`
   - Вставьте в редактор
   - Нажмите "Download" → выберите формат (SVG, PNG)

2. **Graphviz Online:**
   - Откройте https://dreampuf.github.io/GraphvizOnline/
   - Скопируйте содержимое `diagram.dot`
   - Вставьте в редактор
   - Нажмите "Download SVG" или "Download PNG"

3. **Mermaid Live (для ER-диаграмм):**
   - Используйте уже созданные Mermaid диаграммы из `docs/mermaid-2-er-model.md`
   - Откройте https://mermaid.live/
   - Скопируйте код и экспортируйте

## После установки Graphviz

Если после установки команда `dot` все еще не работает:

1. **Перезапустите PowerShell/CMD** (чтобы обновился PATH)

2. **Проверьте PATH вручную:**
   ```powershell
   $env:Path -split ';' | Select-String -Pattern 'graphviz'
   ```

3. **Если Graphviz не в PATH, добавьте вручную:**
   - Найдите папку установки (обычно `C:\Program Files\Graphviz\bin`)
   - Добавьте её в переменную окружения PATH:
     ```powershell
     [Environment]::SetEnvironmentVariable("Path", $env:Path + ";C:\Program Files\Graphviz\bin", "User")
     ```
   - Перезапустите PowerShell

## Быстрая проверка

После установки выполните:
```powershell
cd c:\OSPanel\domains\library-stroll-vue\case-tool
dot -Tsvg diagram.dot -o diagram.svg
```

Если все работает, файл `diagram.svg` будет создан в папке `case-tool`.

