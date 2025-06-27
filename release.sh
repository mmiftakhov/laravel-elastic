#!/bin/bash

# Скрипт для релиза новой версии пакета

if [ -z "$1" ]; then
    echo "Использование: ./release.sh <version>"
    echo "Пример: ./release.sh 1.0.1"
    exit 1
fi

VERSION=$1

echo "Релиз версии $VERSION..."

# Обновить версию в composer.json
sed -i '' "s/\"version\": \"[^\"]*\"/\"version\": \"$VERSION\"/" composer.json

# Создать коммит
git add composer.json
git commit -m "Release version $VERSION"

# Создать тег
git tag v$VERSION

# Отправить изменения
git push origin main
git push origin v$VERSION

echo "Релиз $VERSION завершен!"
echo "Packagist автоматически подхватит новый тег." 