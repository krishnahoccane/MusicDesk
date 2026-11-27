@include('errors.layout', [
    'code' => 403,
    'title' => \App\Support\Text::t('Sign-in link not valid'),
    'message' => $message ?? \App\Support\Text::t('This sign-in link is not valid or has expired.'),
])
