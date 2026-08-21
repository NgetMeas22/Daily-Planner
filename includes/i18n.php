<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'en'));
if (!in_array($lang, ['en', 'kh'], true)) {
    $lang = 'en';
}
$_SESSION['lang'] = $lang;
setcookie('lang', $lang, [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'samesite' => 'Lax',
]);

$translations = [
    'en' => [
        'app_name' => 'Daily Planner',
        'dashboard' => 'Dashboard',
        'planner' => 'Planner',
        'subjects' => 'Subjects',
        'goals' => 'Goals',
        'expenses' => 'Expenses',
        'notes' => 'Notes',
        'profile' => 'Profile',
        'settings' => 'Settings',
        'logout' => 'Logout',
        'language' => 'Language',
        'english' => 'English',
        'khmer' => 'Khmer',
        'theme' => 'Theme',
        'appearance' => 'Appearance',
        'light_mode' => 'Light Mode',
        'dark_mode' => 'Dark Mode',
        'current_theme' => 'Current theme',
        'switch_theme' => 'Switch theme',
        'account' => 'Account',
        'preferences' => 'Preferences',
        'danger_zone' => 'Danger Zone',
        'edit_profile' => 'Edit profile',
        'deep_work_mode' => 'Deep Work Mode',
        'daily_study_reminders' => 'Daily Study Reminders',
        'default_focus_duration' => 'Default Focus Duration',
        'save_preferences' => 'Save Preferences',
        'delete_account' => 'Delete Account',
        'enter_password' => 'Enter your password',
        'loading' => 'Loading...',
        'back' => 'Back',
        'details' => 'Details',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'add' => 'Add',
        'log' => 'Log',
        'register' => 'Register',
        'login' => 'Login',
        'username' => 'Username',
        'password' => 'Password',
        'full_name' => 'Full Name',
        'confirm_password' => 'Confirm Password',
    ],
    'kh' => [
        'app_name' => 'ផែនការប្រចាំថ្ងៃ',
        'dashboard' => 'ផ្ទាំងគ្រប់គ្រង',
        'planner' => 'កាលវិភាគ',
        'subjects' => 'មុខវិជ្ជា',
        'goals' => 'គោលដៅ',
        'expenses' => 'ចំណាយ',
        'notes' => 'កំណត់ចំណាំ',
        'profile' => 'ប្រវត្តិរូប',
        'settings' => 'ការកំណត់',
        'logout' => 'ចេញ',
        'language' => 'ភាសា',
        'english' => 'អង់គ្លេស',
        'khmer' => 'ខ្មែរ',
        'theme' => 'រូបរាង',
        'appearance' => 'រូបរាង',
        'light_mode' => 'ភ្លឺ',
        'dark_mode' => 'ងងឹត',
        'current_theme' => 'របៀបបច្ចុប្បន្ន',
        'switch_theme' => 'ប្ដូររូបរាង',
        'account' => 'គណនី',
        'preferences' => 'ចំណូលចិត្ត',
        'danger_zone' => 'តំបន់គ្រោះថ្នាក់',
        'edit_profile' => 'កែប្រវត្តិរូប',
        'deep_work_mode' => 'របៀបផ្តោតជ្រៅ',
        'daily_study_reminders' => 'ការរំលឹកសិក្សារាល់ថ្ងៃ',
        'default_focus_duration' => 'រយៈពេលផ្តោតលំនាំដើម',
        'save_preferences' => 'រក្សាទុកចំណូលចិត្ត',
        'delete_account' => 'លុបគណនី',
        'enter_password' => 'បញ្ចូលពាក្យសម្ងាត់',
        'loading' => 'កំពុងផ្ទុក...',
        'back' => 'ត្រឡប់ក្រោយ',
        'details' => 'ព័ត៌មានលម្អិត',
        'edit' => 'កែប្រែ',
        'save' => 'រក្សាទុក',
        'cancel' => 'បោះបង់',
        'add' => 'បន្ថែម',
        'log' => 'កត់ត្រា',
        'register' => 'ចុះឈ្មោះ',
        'login' => 'ចូលគណនី',
        'username' => 'ឈ្មោះអ្នកប្រើ',
        'password' => 'ពាក្យសម្ងាត់',
        'full_name' => 'ឈ្មោះពេញ',
        'confirm_password' => 'បញ្ជាក់ពាក្យសម្ងាត់',
    ],
];

function t($key, $default = null)
{
    global $translations, $lang;

    if (isset($translations[$lang][$key])) {
        return $translations[$lang][$key];
    }

    if ($lang !== 'en' && isset($translations['en'][$key])) {
        return $translations['en'][$key];
    }

    return $default ?? $key;
}

function current_lang(): string
{
    return $_SESSION['lang'] ?? 'en';
}

function current_theme(): string
{
    $theme = $_SESSION['theme'] ?? ($_COOKIE['theme_mode'] ?? 'light');
    return in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
}
