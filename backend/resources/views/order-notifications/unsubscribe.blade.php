<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Настройки уведомлений</title>
</head>
<body>
    <main>
        <h1>Уведомления о заказе</h1>
        <p>Заказ: <strong>{{ $orderNumber }}</strong></p>
        @if ($unsubscribed)
            <p>Вы отписались от писем об изменении статуса этого заказа.</p>
        @else
            <p>Письма об изменении статуса этого заказа больше не будут приходить на электронную почту.</p>
            <form method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit">Отписаться</button>
            </form>
        @endif
    </main>
</body>
</html>
