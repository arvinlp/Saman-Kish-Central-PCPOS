# Saman-Kish-Central-PCPOS
Send request from web app to Saman Kish Central PCPOS Devices.

## اتصال دستگاه پوز/کارت‌خوان با استفاده از زبان PHP

یکی از مشکلاتی که برنامه نویسان تحت وب دارند ارسال درخواست خود به دستگاه‌های پوز و دریافت وضعیت پرداخت است،
شرکت [پرداخت الکترونیک سامان کیش](https://www.sep.ir) طی مکاتباتی که برای یکی از پروژه‌ها داشتم متوجه شدم سرویسی ارائه میکنه به نام سنترال پی سی پوز، مزایا این سرویس است که ما می تونیم به دستگاه پوز با استفاده از زیرساخت ارائه شده توسط سامان کیش درخواست خودمون رو ارسال کنیم و جواب رو دریافت کنیم.

کد نمونه برای فریمورک لاراول نوشته شده است، اما فارغ از لاراول است.

### پیش نیاز‌ها

برای استفاده از این کد باید کتابخانه :
-GuzzleHttp
-Carbon

را به پروژه خودتون اضافه کنید.

### GuzzleHttp
برای ارسال/دریافت و مدیریت آسان‌تر درخواست ها از این کتابخانه استفاده شده است.

### Carbon
برای بررسی و نگه‌داری وضعیت کلید احراز هویت از این کتابخانه استفاده شده است.


### بخش اول

Authorization : Password بعنوان secret و کمه Username بعنوان ro.client

- grant_type: نوع دسترسی - نوع دسترسی را می توانید password بگذارید.
- username: درخواست نام کاربری را باید به سامان کیش دهید.
- password: درخواست گذرواژه را باید به سامان کیش دهید.
- scope: محدوده
 1. switcha pimanagement offline_access
 2. SepCentralPcPos openid
 2.1.  محدود مورد استفاده ما در این پروژه بوده است.
```markdown

$client = new GuzzleHttp\Client();

$response = $client->request('POST', "https://idn.seppay.ir/connect/token",[
    'headers' => [
        'Authorization' => '*************'
   ],
    'form_params' => [
        'grant_type' => $sep_grant_type,
        'username' => $sep_username,
        'password' => $sep_password,
        'scope' => $sep_scope,
    ]
]);
$access = json_decode($response->getBody());
```
اطلاعات برگشتی به شرح زیر است :
- access_token : کد احراز
- token_type : نوع احراز سنجی
- expires_in : مدت زمان اعتبار کد دسترسی به ثانیه
- refresh_token : در نوع درخواستی ما موجود نبود قبل از استفاده از وجود این آیتم مطمین شوید.

در حال تکمیل توضیحات
