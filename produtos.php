<?php
// A tela de produtos agora é uma aba dentro de index.php. Este arquivo só redireciona links antigos.
$qs = isset($_GET['editar']) ? '?editar='.(int)$_GET['editar'] : '?aba=produtos';
header('Location: index.php'.$qs, true, 302);
exit;
