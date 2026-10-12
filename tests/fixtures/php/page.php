<?php $this->layout('layout', ['title' => 'Home']) ?>
<?php $this->start('sidebar') ?>side<?php $this->stop() ?>
body:<?= $this->e($name) ?>
<?= $this->insert('partial', ['x' => '<1>']) ?>
