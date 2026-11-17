<!doctype html>
<html lang="ru">
<body>
    <p>Здравствуйте!</p>
    <p>{{ $bodyText }}</p>
    <p>Номер заказа: <strong>{{ $orderNumber }}</strong></p>
    @if ($statusLabel)
        <p>Текущий статус: <strong>{{ $statusLabel }}</strong></p>
    @endif
    @if ($unsubscribeUrl)
        <p><a href="{{ $unsubscribeUrl }}">Отписаться от писем об изменении статуса заказа</a></p>
    @endif
</body>
</html>
