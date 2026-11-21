<?php
$entityConfig = [
    'slug' => 'kategori', 'table' => 'drug_categories', 'title' => 'Kategori obat', 'singular' => 'Kategori obat', 'plural' => 'Daftar kategori obat',
    'description' => 'Kelola pengelompokan obat untuk memudahkan pencarian dan pengelolaan katalog.',
    'search_placeholder' => 'Cari nama atau keterangan kategori...',
    'from_sql' => 'FROM drug_categories e', 'select_sql' => 'SELECT e.id, e.category_name, e.description, e.is_active FROM drug_categories e',
    'search_columns' => ['e.category_name', 'e.description'], 'order_by' => 'e.category_name ASC',
    'fields' => [
        ['name'=>'category_name','label'=>'Nama kategori','type'=>'text','required'=>true,'max'=>100,'placeholder'=>'Contoh: Antibiotik'],
        ['name'=>'description','label'=>'Keterangan','type'=>'textarea','max'=>255,'wide'=>true,'placeholder'=>'Keterangan kategori (opsional)'],
    ],
    'columns' => [ ['key'=>'category_name','label'=>'Kategori'], ['key'=>'description','label'=>'Keterangan'] ],
];
require __DIR__ . '/_entity.php';
