@include('errors.layout', ['code' => 429, 'title' => \App\Support\Text::t('Too many requests'), 'message' => \App\Support\Text::t('Please wait a minute and try again.')])
