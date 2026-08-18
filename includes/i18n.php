<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'en');
if (!in_array($lang, ['en', 'kh'], true)) {
    $lang = 'en';
}
$_SESSION['lang'] = $lang;

$translations = [
    'en' => [
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
    ],
    'kh' => [
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
    ],
];

function t($key)
{
    global $translations, $lang;
    return $translations[$lang][$key] ?? $key;
}

