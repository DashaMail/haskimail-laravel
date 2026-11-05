# Haskimail для Laravel

Почтовый драйвер, API-клиент и приёмник вебхуков [Haskimail](https://haskimail.ru) для Laravel 11–13.

```php
Mail::to($user)->send(new OrderShipped($order));   // письмо уходит через API Haskimail
```

- **Драйвер `haskimail`** для `config/mail.php`: работают Mailable, `Mail::raw`, уведомления, очереди, `Mail::fake()`, failover- и roundrobin-мейлеры.
- **Возможности API**: каналы (MessageStream), шаблоны, теги, метаданные, трекинг открытий и кликов, вложения и встроенные изображения, собственные заголовки.
- **Прямой клиент** для `/email`, `/email/batch`, `/email/withTemplate`, `/email/batchWithTemplates` с типизированными результатами.
- **Вебхуки**: проверка подписи и Basic-auth, защита от дублей, обработка в очереди, Laravel-события `MessageDelivered`, `MessageBounced` и другие.
- **Надёжность**: повторы только там, где письмо точно не ушло, типизированные исключения, проверка писем до отправки, `Haskimail::fake()` для тестов.

## Требования

PHP 8.2+ (`ext-mbstring`), Laravel 11.44+, 12 или 13.

## Установка

```bash
composer require dashamail/haskimail-laravel
```

Сервис-провайдер и фасад подключаются автоматически.

```dotenv
MAIL_MAILER=haskimail
HASKIMAIL_TOKEN=ваш-серверный-ключ          # Сервер → «API-ключи»
HASKIMAIL_MESSAGE_STREAM=                   # необязательно: ID канала по умолчанию, например 2081
```

Добавьте мейлер в `config/mail.php`:

```php
'mailers' => [
    'haskimail' => [
        'transport' => 'haskimail',
        // необязательно: 'token', 'message_stream', 'endpoint',
        // 'timeout', 'connect_timeout', 'retries', 'retry_delay'
    ],
    // ...
],
```

Адрес `From` (`MAIL_FROM_ADDRESS`) должен быть на подтверждённом в Haskimail домене.

Опубликовать конфигурацию (по желанию):

```bash
php artisan vendor:publish --tag=haskimail-config
```

Каждая опция ищется по порядку: запись мейлера в `config/mail.php` → `services.haskimail` → `config/haskimail.php`. Мейлер со своим `token` работает с другим сервером Haskimail и не наследует глобальные `endpoint` и `message_stream`:

```php
'transactional' => ['transport' => 'haskimail'],
'marketing'     => ['transport' => 'haskimail', 'token' => env('HASKIMAIL_MARKETING_TOKEN'), 'message_stream' => '2093'],
```

## Отправка писем

Любой Mailable работает без изменений. Поля Laravel переводятся в поля API так:

| Laravel | Haskimail API |
|---|---|
| `from`, `to`, `cc`, `bcc`, `replyTo` | `From`, `To`, `Cc`, `Bcc`, `ReplyTo` |
| `subject` | `Subject` |
| HTML / текстовое представление | `HtmlBody` / `TextBody` |
| `tags: ['orders']` | `Tag` (Haskimail допускает **один** тег) |
| `metadata: ['order_id' => '1042']` | `Metadata` (приходит в вебхуки как `custom_vars`) |
| `headers()` / свои заголовки | `Headers` |
| `attachments()`, `$message->embed()` | `Attachments` (с `ContentID` для встроенных) |

Имена отправителя и получателей из букв, цифр и пробелов передаются как есть. Остальные (с запятыми, точками, кавычками и т. п.) транспорт кодирует по RFC 2047: проверено на живом API, такие имена доходят без искажений.

ID письма из ответа API доступен в событии `MessageSent`:

```php
use Illuminate\Mail\Events\MessageSent;

Event::listen(function (MessageSent $event) {
    $haskimailId = $event->sent->getMessageId();
});
```

### Опции Haskimail

Подключите трейт `InteractsWithHaskimail` в Mailable или в свой подкласс `MailMessage` для уведомлений:

```php
use Haskimail\Laravel\Mail\InteractsWithHaskimail;

final class OrderShipped extends Mailable implements ShouldQueue
{
    use InteractsWithHaskimail, Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->haskimailStream('2081')     // канал (MessageStream)
            ->haskimailTrackOpens()        // TrackOpens: true
            ->haskimailTrackLinks();       // TrackLinks: true
    }
}

// или в месте отправки
Mail::to($user)->send((new OrderShipped($order))->haskimailStream('2081'));
```

Опции сериализуются вместе с письмом, так что очереди работают. Без трейта те же опции задаются заголовками `X-Haskimail-Message-Stream`, `X-Haskimail-Track-Opens` и `X-Haskimail-Track-Links` (`true`/`false`), `X-Haskimail-Template` (число — TemplateId, иначе TemplateAlias). Транспорт превращает их в поля API и не отправляет получателю. Префикс `X-Haskimail-` зарезервирован: заголовок с опечаткой (`X-Haskimail-Mesage-Stream`) вызывает исключение, а не молча игнорируется.

### Шаблоны

```php
use Haskimail\Laravel\Mail\TemplateMailable;

final class Welcome extends TemplateMailable
{
    public function __construct(public User $user) {}

    public function template(): int|string
    {
        return 'welcome';        // int — TemplateId, string — TemplateAlias (даже '2024')
    }

    public function templateModel(): array
    {
        // Замена буквальная: ключ — фрагмент текста шаблона вместе с разделителями.
        return ['{{name}}' => e($this->user->name)];
    }
}

Mail::to($user)->send(new Welcome($user));
```

- `TemplateMailable` отправляется через `/email/withTemplate` и берёт тему из шаблона, если вы не задали её через `->subject()`.
- **Значения `TemplateModel` Haskimail подставляет без экранирования**, в том числе в HTML (проверено на живом API). Экранируйте пользовательские данные через `e()`. Учтите, что та же подстановка попадёт и в текстовую версию, и в тему. Переводы строк в значениях запрещены.
- В обычном Mailable или уведомлении можно вызвать `->haskimailTemplate(4521, [...])`, но Laravel всегда подставляет тему из имени класса. Задайте тему сами или используйте `TemplateMailable`.
- Шаблоны рендерит только Haskimail. Если мейлер может отправить письмо через другой транспорт (например, failover с SMTP в запасе), отправка шаблонного письма прерывается `InvalidMessageException`, а не уходит пустым письмом. Разрешены failover между серверами Haskimail и мейлеры `log`/`array` (в том числе как запасные) для локальной разработки. Мейлеры из `Mail::build()` не проверить, поэтому шаблонные письма через них тоже отклоняются.

### Вложения и встроенные изображения

Обычные вложения Laravel работают как есть. Встроенные картинки — через `$message->embed()` / `embedData()` в Blade-шаблоне: транспорт сам выставит `ContentID`, совпадающий со ссылкой `cid:` в HTML.

### Проверки до отправки

Ещё до запроса к API клиент отклоняет (`InvalidMessageException`):

- переводы строк и управляющие символы в однострочных полях (`From`, `To`, `Subject`, `Tag`, `MessageStream`, заголовки, метаданные, имена вложений). API Haskimail сам этого не делает: перевод строки в `Subject` или в значении заголовка превращается в новый заголовок письма. Транспорт Laravel вместо отказа схлопывает переводы строк в теме в пробел;
- имена заголовков вне RFC 5322 и MIME-типы вложений с параметром имени файла (`; name=...`);
- переводы строк в значениях `TemplateModel`: они подставляются и в тему;
- тему длиннее 2000 и тег длиннее 1000 символов;
- пакет не из 1–500 писем и письмо шаблонного пакета без `TemplateId`/`TemplateAlias`.

Только в транспорте Laravel дополнительно проверяются: не больше 50 получателей, ровно один `From`, запрещённые Haskimail расширения вложений (`exe`, `bat`, `vbs`, `msi` и т. д., в том числе замаскированные `invoice.exe.` или `invoice\u{202E}fdp.exe`), адреса, которые могли бы распасться на нескольких получателей. Общий размер письма (до 10 МБ после base64) проверяет API. Большие письма лучше отправлять из очереди.

## Прямой API-клиент

```php
use Haskimail\Laravel\Facades\Haskimail;

$result = Haskimail::send([
    'From' => 'Магазин <shop@example.com>',
    'To' => 'ivan@example.com',
    'Subject' => 'Ваш заказ №1042 оформлен',
    'HtmlBody' => '<p>Здравствуйте!</p>',
    'MessageStream' => '2081',
]);

$result->messageId;      // '6f1c0b2d...'
$result->submittedAt;    // CarbonImmutable, Europe/Moscow
$result->messageIds();   // по одному ID на получателя (To, Cc и Bcc)
```

Поля передаются ровно в формате API ([документация](https://haskimail.ru/developer/api/email-api)), адреса — строкой, поэтому имена с запятыми кодируйте сами или используйте драйвер. Фасад работает с контрактом `Haskimail\Laravel\Contracts\Client`, его же можно внедрять в свои классы.

### Пакетная отправка

```php
$batch = Haskimail::sendBatch($messages);         // от 1 до 500 писем за запрос

foreach ($batch->rejected() as $index => $result) {
    Log::warning("Письмо #{$index} отклонено: {$result->errorCode} {$result->message}");
}

$batch->allAccepted();   // bool
$batch->retryable();     // отклонённые с ErrorCode 36/100 — их можно повторить позже

// Больше 500 писем — делите на части
collect($messages)->chunk(500)->each(fn ($chunk) => Haskimail::sendBatch($chunk->values()->all()));
```

API отвечает HTTP 200, даже если часть писем отклонена, поэтому `sendBatch()` не бросает исключений на отказ отдельного письма: проверяйте результат.

Шаблоны: `Haskimail::sendWithTemplate([...])` и `Haskimail::sendBatchWithTemplates([...])`. Другой сервер: `Haskimail::withToken($token)->send([...])`.

## Ошибки и повторы

Все исключения пакета реализуют `Haskimail\Laravel\Exceptions\HaskimailException`.

| Исключение | Когда | Повторять? | Failover-мейлер |
|---|---|---|---|
| `InvalidMessageException` | Письмо некорректно, запрос не отправлялся | Нет | Нет |
| `InvalidRequestException` | 4xx/422: неверный адрес, неподтверждённый отправитель, нет канала или шаблона | Нет | **Нет**: повторная отправка через другого провайдера обошла бы стоп-листы Haskimail |
| `SendingUnavailableException` | ErrorCode 4, 35 (аккаунт заблокирован, нет средств), 36, 100 (временная ошибка) | 36, 100 — да | Да |
| `AuthenticationException` | 401/403, ErrorCode 10 | Нет | Да |
| `RateLimitException` | 429 | Да | Да |
| `ServerException` | 5xx | 500, 503 — да | Да |
| `ConnectionException` | Сеть, DNS, TLS, тайм-аут | См. `mightHaveBeenSent()` | Да |
| `UnexpectedResponseException` | Ответ не удалось разобрать | Нет | Да |

```php
use Haskimail\Laravel\Enums\ErrorCode;
use Haskimail\Laravel\Exceptions\InvalidRequestException;

try {
    Haskimail::send($message);
} catch (InvalidRequestException $e) {
    $e->errorCode();                        // 6
    $e->is(ErrorCode::InvalidEmailAddress); // true
    $e->apiMessage();                       // текст ошибки от Haskimail
}
```

**Автоматические повторы.** Клиент сам повторяет запрос (по умолчанию до 2 раз, экспоненциальная задержка с jitter, учитывается `Retry-After`) только когда письмо точно не принято: HTTP 429 и 503, ErrorCode 36 и 100, соединение не установлено. HTTP 500, 502/504 и тайм-аут чтения **не повторяются**: письмо могло уже уйти, а ключей идемпотентности в API нет. Документация Haskimail советует повторить запрос после 500 «позже». Для этого `isRetryable()` у такого исключения возвращает `true`: повторяйте через очередь с задержкой, понимая риск дубля. Суммарное ожидание между попытками — не больше 20 секунд. Перенаправления (3xx) не выполняются никогда, чтобы токен не ушёл на чужой хост.

`mightHaveBeenSent()` у исключения говорит, могло ли письмо уже быть принято. Failover-мейлер переключается на запасной транспорт и при тайм-ауте или 5xx: это выбор в пользу доставки, а не защиты от дублей.

Если ответ 2xx не удалось прочитать, одиночная отправка считается успешной (`messageId === null`). Иначе повторы очереди привели бы к дублям.

## Вебхуки

```dotenv
HASKIMAIL_WEBHOOKS_ENABLED=true
HASKIMAIL_WEBHOOK_SIGNING_KEY=ключ-подписи      # Сервер → Канал → «Вебхуки»
HASKIMAIL_WEBHOOK_USERNAME=haskimail
HASKIMAIL_WEBHOOK_PASSWORD=длинный-случайный-пароль
HASKIMAIL_WEBHOOK_QUEUE=true                    # обрабатывать события в очереди
```

В Haskimail укажите URL `https://haskimail:пароль@ваш-сайт.ru/haskimail/webhook`. Маршрут `POST /haskimail/webhook` (имя `haskimail.webhook`) регистрируется без CSRF, путь меняется через `HASKIMAIL_WEBHOOK_PATH`.

```php
use Haskimail\Laravel\Enums\BounceCategory;
use Haskimail\Laravel\Webhooks\Events\MessageBounced;

Event::listen(function (MessageBounced $e) {
    if ($e->event->bounceCategory === BounceCategory::Hard) {
        User::where('email', $e->event->recipient)->update(['email_bounced_at' => now()]);
    }
});
```

| Событие Laravel | `event` в вебхуке |
|---|---|
| `MessageDelivered` | `delivered` |
| `MessageBounced` | `bounced` |
| `SpamComplaintReceived` | `complained` |
| `MessageOpened` | `opened` |
| `LinkClicked` | `clicked` |
| `SubscriptionChanged` | `subscriptionchange` |
| `WebhookReceived` | любое, включая новые типы |

`$e->event` — это `WebhookEvent` с типизированными полями: `recipient`, `messageId`, `tag`, `metadata` (уже раскодированный `custom_vars`), `occurredAt` (UTC), `bounceCode`, `bounceCategory`, `originalUrl`, `suppressSending`, `suppressionReason` и т. д. Сырые данные доступны через `$e->event->get('field')`. Поле `secret` в событии не сохраняется.

**Аутентификация.** Включите оба способа:

- **Подпись** (`signing_key`): у каждого события проверяется `secret = md5(recipient . message_id . key)`. Эта схема Haskimail защищает только получателя и ID письма, а не тип события и остальные поля: перехваченное событие можно переиграть с другим `event`. Пакет дополнительно сверяет формат `recipient` (адрес с буквенным доменом верхнего уровня) и `message_id` (32 hex-символа или UUID), чтобы часть одного поля нельзя было перенести в другое без смены подписи.
- **Basic auth** (`username` + `password`): аутентифицирует запрос целиком.

Если заданы оба способа, проходить должны оба. Если не задан ни один или задан только логин без пароля, маршрут отвечает 503: Haskimail будет повторять доставку около часа, пока вы не исправите конфигурацию, и события не потеряются.

**Время ответа.** Haskimail ждёт ответа не больше 10 секунд и тайм-аут не повторяет. Включите `HASKIMAIL_WEBHOOK_QUEUE=true` (и при желании `HASKIMAIL_WEBHOOK_QUEUE_CONNECTION` / `HASKIMAIL_WEBHOOK_QUEUE_NAME`): маршрут проверит подпись, поставит задание `ProcessWebhookEvent` в очередь и сразу ответит 200, а слушатели выполнятся в воркере с повторами.

**Коды ответа.** 200 — обработано, поставлено в очередь или дубль. 401/403 — неверные учётные данные или подпись, Haskimail не повторяет. 5xx — слушатель упал или событие сейчас обрабатывается другим воркером, Haskimail повторит (до 60 раз, примерно раз в минуту).

**Дубли.** Haskimail может прислать событие дважды. Повтор отбрасывается по ключу из рекомендованных документацией полей `event`, `message_id`, `recipient`, времени события и, для кликов, `url_original` (чтобы два клика по разным ссылкам в одну секунду не склеились). Ключи хранятся в кеше (`deduplicate.store`, `ttl`). Используйте общий для всех серверов кеш (Redis и т. п.). Событие считается обработанным, когда слушатели отработали без исключения. Слушатели с `ShouldQueue` считаются выполненными в момент постановки в очередь, так что их ошибки обрабатывайте средствами очереди.

Ограничить частоту запросов: `'middleware' => ['throttle:600,1']` в `config/haskimail.php`. Пользовательские middleware выполняются до проверки подписи. В одном запросе принимается не больше 100 событий.

## Тестирование

```php
use Haskimail\Laravel\Facades\Haskimail;

$fake = Haskimail::fake();

Mail::to('ivan@example.com')->send(new Welcome($user));   // уходит в фейк, HTTP-запросов нет

$fake->assertSentCount(1);
$fake->assertSentTo('ivan@example.com');
$fake->assertSent(fn (array $message, string $endpoint) =>
    $endpoint === '/email/withTemplate' && $message['TemplateAlias'] === 'welcome');

$fake->failNextWith(ApiException::fromResponse(422, ['ErrorCode' => 6, 'Message' => 'Некорректный email-адрес получателя']));
```

Фейк перехватывает фасад, контракт `Client` и мейлеры, у которых в `config/mail.php` не переопределены `token`/`endpoint`/таймауты, даже если мейлер уже использовался до вызова `fake()`. `Mail::fake()` и `Haskimail::shouldReceive()` тоже работают.

Проверка без отправки: `HASKIMAIL_TOKEN=HASKIMAIL_API_TEST` — API проверит запрос и ответит в обычном формате, но ничего не отправит. Для сквозных тестов используйте сервер-песочницу Haskimail и адреса `hardbounce@bounce-testing.haskimail.ru`, `spamnotification@bounce-testing.haskimail.ru` и т. д.

## Безопасность

- Токен проверяется (только печатаемый ASCII), не выводится в `dd()`/`var_dump()` и помечен `#[SensitiveParameter]` везде, где передаётся, поэтому не попадает в аргументы стектрейсов. Сообщения об ошибках конфигурации не содержат логинов и паролей из URL.
- Endpoint обязан быть `https://` без логина, пароля и query-строки. Перенаправления не выполняются.
- Из имён удаляются управляющие, невидимые символы и символы смены направления текста (bidi). Имена со спецсимволами кодируются по RFC 2047. Переводы строк в однострочных полях запрещены (см. «Проверки до отправки»).
- Имена вложений очищаются от путей, управляющих и невидимых символов.
- Подписи и пароли вебхуков сравниваются за постоянное время.
- Laravel передаёт заголовки исходящих HTTP-запросов, включая `X-Haskimail-Server-Token`, в события `RequestSending`/`ResponseReceived`. Их записывают Telescope, Pulse, Nightwatch, Sentry и ваши собственные слушатели. Скройте токен, например:

  ```php
  Telescope::hideRequestHeaders(['X-Haskimail-Server-Token']);
  ```

Об уязвимостях сообщайте приватно, см. [SECURITY.md](SECURITY.md).

## Разработка

```bash
composer install
composer check      # Pint + PHPStan (max) + PHPUnit
```

## Лицензия

MIT, см. [LICENSE](LICENSE).
