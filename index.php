<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function brl($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function tam(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s,'UTF-8') : (int)preg_match_all('/./us',$s); }

function tel_fmt($d): string { $d=preg_replace('/\D+/','',(string)($d??'')); if(strlen($d)===11) return sprintf('(%s) %s-%s',substr($d,0,2),substr($d,2,5),substr($d,7)); if(strlen($d)===10) return sprintf('(%s) %s-%s',substr($d,0,2),substr($d,2,4),substr($d,6)); return $d; }

// Perfis de usuário (chave gravada no banco => rótulo exibido).
$perfis=['gerente'=>'Gerente','garcom'=>'Garçom','cozinha'=>'Cozinha','caixa'=>'Caixa'];

// Aba ativa: "comandas" (padrão), "produtos" ou "usuarios". Funciona mesmo sem JavaScript (?aba=usuarios).
$aba = 'comandas';
if (($_GET['aba'] ?? '') === 'produtos' || isset($_GET['editar'])) $aba = 'produtos';
elseif (($_GET['aba'] ?? '') === 'usuarios' || isset($_GET['editar_usuario'])) $aba = 'usuarios';
$erroC=''; $sucessoC=''; $erroP=''; $sucessoP=''; $editando=null;
$erroU=''; $sucessoU=''; $editandoU=null; $semTabelaU=false; $formU=null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
 $acao=(string)($_POST['acao']??'');
 try {
  // ---------- COMANDAS ----------
  if ($acao==='criar_comanda') {
   $aba='comandas';
   $observacao=trim((string)($_POST['observacao']??'')); $itens=[];
   foreach (($_POST['quantidade']??[]) as $pid=>$qraw) {
    $pid=filter_var($pid,FILTER_VALIDATE_INT); $q=filter_var($qraw,FILTER_VALIDATE_INT);
    if (!$pid || !$q || $q<1 || $q>99) continue;
    $s=$pdo->prepare('SELECT id,nome,preco FROM produtos WHERE id=? AND ativo=1'); $s->execute([$pid]); $p=$s->fetch();
    if ($p) $itens[]=['p'=>$p,'q'=>$q];
   }
   if (!$itens) $erroC='Selecione ao menos um produto e informe uma quantidade maior que zero.';
   else {
    $pdo->beginTransaction();
    $s=$pdo->prepare('INSERT INTO comandas (observacao) VALUES (?)'); $s->execute([$observacao!==''?$observacao:null]);
    $id=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('UPDATE comandas SET numero=? WHERE id=?'); $s->execute([$id,$id]);
    $si=$pdo->prepare('INSERT INTO itens_comanda (comanda_id,produto_id,nome_produto,quantidade,preco_unitario) VALUES (?,?,?,?,?)');
    foreach($itens as $it) $si->execute([$id,$it['p']['id'],$it['p']['nome'],$it['q'],$it['p']['preco']]);
    $pdo->commit(); header('Location: index.php?criada='.$id); exit;
   }
  } elseif ($acao==='entregar') {
   $id=filter_var($_POST['comanda_id']??'',FILTER_VALIDATE_INT);
   if($id){$s=$pdo->prepare("UPDATE comandas SET status='entregue',data_entrega=NOW() WHERE id=? AND status='aberta'");$s->execute([$id]);}
   header('Location: index.php?entregue=1'); exit;
  } elseif ($acao==='observacao') {
   $id=filter_var($_POST['comanda_id']??'',FILTER_VALIDATE_INT); $obs=trim((string)($_POST['observacao']??''));
   if($id){$s=$pdo->prepare("UPDATE comandas SET observacao=? WHERE id=? AND status='aberta'");$s->execute([$obs!==''?$obs:null,$id]);}
   header('Location: index.php?obs=1'); exit;

  // ---------- PRODUTOS ----------
  } elseif ($acao==='salvar') {
   $aba='produtos';
   $id=filter_var($_POST['id']??'',FILTER_VALIDATE_INT)?:null;
   $nome=trim((string)($_POST['nome']??'')); $desc=trim((string)($_POST['descricao']??'')); $cat=trim((string)($_POST['categoria']??''));
   $preco=filter_var(str_replace(',','.',trim((string)($_POST['preco']??''))),FILTER_VALIDATE_FLOAT); $ativo=isset($_POST['ativo'])?1:0;
   if($nome===''||tam($nome)>120) $erroP='Informe o nome do produto (até 120 caracteres).';
   elseif($cat===''||tam($cat)>60) $erroP='Informe uma categoria (até 60 caracteres).';
   elseif(tam($desc)>255) $erroP='A descrição pode ter até 255 caracteres.';
   elseif($preco===false||$preco<0||$preco>99999999) $erroP='Informe um preço válido.';
   else {
    if($id){$s=$pdo->prepare('UPDATE produtos SET nome=?,descricao=?,categoria=?,preco=?,ativo=? WHERE id=?');$s->execute([$nome,$desc!==''?$desc:null,$cat,$preco,$ativo,$id]);$msg='atualizado';}
    else{$s=$pdo->prepare('INSERT INTO produtos(nome,descricao,categoria,preco,ativo) VALUES(?,?,?,?,?)');$s->execute([$nome,$desc!==''?$desc:null,$cat,$preco,$ativo]);$msg='cadastrado';}
    header('Location: index.php?aba=produtos&ok='.$msg); exit;
   }
  } elseif ($acao==='alternar') {
   $id=filter_var($_POST['id']??'',FILTER_VALIDATE_INT);
   if($id){$s=$pdo->prepare('UPDATE produtos SET ativo=IF(ativo=1,0,1) WHERE id=?');$s->execute([$id]);}
   header('Location: index.php?aba=produtos&ok=alternado'); exit;

  // ---------- USUÁRIOS ----------
  } elseif ($acao==='salvar_usuario') {
   $aba='usuarios';
   $id=filter_var($_POST['id']??'',FILTER_VALIDATE_INT)?:null;
   $nomeU=trim((string)($_POST['nome']??'')); $email=strtolower(trim((string)($_POST['email']??'')));
   $telU=preg_replace('/\D+/','',(string)($_POST['telefone']??'')); $perfil=(string)($_POST['perfil']??'');
   $senha=(string)($_POST['senha']??''); $senha2=(string)($_POST['senha2']??''); $ativoU=isset($_POST['ativo'])?1:0;
   // Em caso de erro, o formulário volta preenchido (menos as senhas).
   $formU=['id'=>$id??0,'nome'=>$nomeU,'email'=>$email,'telefone'=>$telU,'perfil'=>$perfil,'ativo'=>$ativoU];
   if($nomeU===''||tam($nomeU)<3||tam($nomeU)>120) $erroU='Informe o nome completo (de 3 a 120 caracteres).';
   elseif($email===''||strlen($email)>150||!filter_var($email,FILTER_VALIDATE_EMAIL)) $erroU='Informe um e-mail válido (até 150 caracteres).';
   elseif($telU!==''&&!in_array(strlen($telU),[10,11],true)) $erroU='Telefone inválido. Use DDD + número, ex.: (49) 99999-9999.';
   elseif(!isset($perfis[$perfil])) $erroU='Selecione um perfil de acesso válido.';
   elseif(!$id&&$senha==='') $erroU='Defina uma senha para o novo usuário.';
   elseif($senha!==''&&tam($senha)<8) $erroU='A senha deve ter pelo menos 8 caracteres.';
   elseif($senha!==''&&strlen($senha)>72) $erroU='A senha pode ter no máximo 72 caracteres.';
   elseif($senha!==$senha2) $erroU='A confirmação não confere com a senha digitada.';
   else {
    $s=$pdo->prepare('SELECT id FROM usuarios WHERE email=? AND id<>?'); $s->execute([$email,$id??0]);
    if($s->fetch()) $erroU='Já existe um usuário cadastrado com este e-mail.';
    else {
     $tel=$telU!==''?$telU:null;
     if($id){
      if($senha!==''){$s=$pdo->prepare('UPDATE usuarios SET nome=?,email=?,telefone=?,perfil=?,ativo=?,senha_hash=? WHERE id=?');$s->execute([$nomeU,$email,$tel,$perfil,$ativoU,password_hash($senha,PASSWORD_DEFAULT),$id]);}
      else{$s=$pdo->prepare('UPDATE usuarios SET nome=?,email=?,telefone=?,perfil=?,ativo=? WHERE id=?');$s->execute([$nomeU,$email,$tel,$perfil,$ativoU,$id]);}
      $msg='atualizado';
     } else {
      $s=$pdo->prepare('INSERT INTO usuarios(nome,email,telefone,perfil,senha_hash,ativo) VALUES(?,?,?,?,?,?)');
      $s->execute([$nomeU,$email,$tel,$perfil,password_hash($senha,PASSWORD_DEFAULT),$ativoU]); $msg='cadastrado';
     }
     header('Location: index.php?aba=usuarios&uok='.$msg); exit;
    }
   }
  } elseif ($acao==='alternar_usuario') {
   $aba='usuarios';
   $id=filter_var($_POST['id']??'',FILTER_VALIDATE_INT);
   if($id){$s=$pdo->prepare('UPDATE usuarios SET ativo=IF(ativo=1,0,1) WHERE id=?');$s->execute([$id]);}
   header('Location: index.php?aba=usuarios&uok=alternado'); exit;
  }
 } catch(Throwable $ex) {
  if($pdo->inTransaction()) $pdo->rollBack();
  error_log('Erro: '.$ex->getMessage());
  if($aba==='usuarios'){
   $cod=($ex instanceof PDOException)?(int)($ex->errorInfo[1]??0):0;
   if($cod===1062) $erroU='Já existe um usuário cadastrado com este e-mail.';
   elseif($cod===1146) $erroU='A tabela de usuários ainda não existe. Importe o arquivo migracao_usuarios.sql.';
   else $erroU='Não foi possível salvar o usuário.';
  }
  elseif($aba==='produtos') $erroP='Não foi possível salvar a alteração.';
  else $erroC='Não foi possível concluir a operação. Confira banco e configuração.';
 }
}

if(isset($_GET['criada'])) $sucessoC='Comanda #'.(int)$_GET['criada'].' registrada com sucesso.';
if(isset($_GET['entregue'])) $sucessoC='Comanda marcada como entregue.';
if(isset($_GET['obs'])) $sucessoC='Observação atualizada.';
$okMsgs=['atualizado'=>'Produto atualizado.','cadastrado'=>'Produto cadastrado.','alternado'=>'Disponibilidade atualizada.'];
if(isset($_GET['ok'],$okMsgs[$_GET['ok']])) $sucessoP=$okMsgs[$_GET['ok']];
if(isset($_GET['editar'])){$id=filter_var($_GET['editar'],FILTER_VALIDATE_INT);if($id){$s=$pdo->prepare('SELECT * FROM produtos WHERE id=?');$s->execute([$id]);$editando=$s->fetch()?:null;}}

$okMsgsU=['cadastrado'=>'Usuário cadastrado com sucesso.','atualizado'=>'Usuário atualizado.','alternado'=>'Status do usuário atualizado.'];
if(isset($_GET['uok'],$okMsgsU[$_GET['uok']])) $sucessoU=$okMsgsU[$_GET['uok']];
$usuarios=[];
try {
 // Nunca selecionamos senha_hash para exibição: ele não precisa sair do banco.
 if(isset($_GET['editar_usuario'])){$id=filter_var($_GET['editar_usuario'],FILTER_VALIDATE_INT);if($id){$s=$pdo->prepare('SELECT id,nome,email,telefone,perfil,ativo FROM usuarios WHERE id=?');$s->execute([$id]);$editandoU=$s->fetch()?:null;}}
 $usuarios=$pdo->query('SELECT id,nome,email,telefone,perfil,ativo,criado_em FROM usuarios ORDER BY nome')->fetchAll();
} catch(PDOException $ex) {
 error_log('Erro usuários: '.$ex->getMessage());
 if((int)($ex->errorInfo[1]??0)===1146){$semTabelaU=true;$erroU='A tabela de usuários ainda não existe neste banco. Importe o arquivo migracao_usuarios.sql e recarregue a página.';}
 else $erroU='Não foi possível carregar os usuários.';
}
if($formU===null) $formU=$editandoU??['id'=>0,'nome'=>'','email'=>'','telefone'=>'','perfil'=>'garcom','ativo'=>1];
$modoEdicaoU=(int)($formU['id']??0)>0;

$todosProdutos=$pdo->query('SELECT * FROM produtos ORDER BY categoria,nome')->fetchAll();
$produtosAtivos=array_values(array_filter($todosProdutos,fn($p)=>(int)$p['ativo']===1));
$comandas=$pdo->query("SELECT c.*,COALESCE(SUM(i.quantidade*i.preco_unitario),0) total,COALESCE(SUM(i.quantidade),0) unidades FROM comandas c LEFT JOIN itens_comanda i ON i.comanda_id=c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 30")->fetchAll();
$itensPor=[];
if($comandas){$ids=array_map(fn($c)=>(int)$c['id'],$comandas);$ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT * FROM itens_comanda WHERE comanda_id IN ($ph) ORDER BY id");$s->execute($ids);foreach($s->fetchAll() as $i)$itensPor[$i['comanda_id']][]=$i;}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bistrô Sabor da Vila</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="topbar"><div class="wrap topbar-inner"><a class="brand" href="index.php"><span class="brand-icon">SV</span><span>Bistrô <b>Sabor da Vila</b><small>PAINEL DE COMANDAS</small></span></a>
<nav id="tabs" role="tablist"><a href="index.php?aba=comandas" data-tab="comandas" role="tab" class="<?=$aba==='comandas'?'nav-active':''?>">Comandas</a><a href="index.php?aba=produtos" data-tab="produtos" role="tab" class="<?=$aba==='produtos'?'nav-active':''?>">Produtos</a><a href="index.php?aba=usuarios" data-tab="usuarios" role="tab" class="<?=$aba==='usuarios'?'nav-active':''?>">Usuários</a></nav></div></header>
<main class="wrap">

<!-- ===================== ABA: COMANDAS ===================== -->
<div class="tab-pane" id="aba-comandas" <?=$aba==='comandas'?'':'hidden'?>>
<section class="hero"><div><span class="eyebrow">OPERAÇÃO DA COZINHA</span><h1>Comandas do restaurante</h1><p>Registre pedidos, acompanhe o preparo e marque pedidos entregues.</p></div><div class="hero-mark">☕</div></section>
<?php if($erroC):?><div class="alert error"><?=e($erroC)?></div><?php endif;?><?php if($sucessoC):?><div class="alert success"><?=e($sucessoC)?></div><?php endif;?>
<section class="layout"><div class="panel"><div class="panel-heading"><div><span class="step">01 / NOVO PEDIDO</span><h2>Abrir comanda</h2></div><span class="pill">Cozinha</span></div>
<form method="post" action="index.php"><input type="hidden" name="acao" value="criar_comanda">
<?php if(!$produtosAtivos):?><div class="empty">Nenhum produto ativo. Cadastre produtos na aba <a href="index.php?aba=produtos" data-tab="produtos">Produtos</a>.</div><?php else:?><div class="product-list"><?php $catAtual='';foreach($produtosAtivos as $p):if($catAtual!==$p['categoria']):$catAtual=$p['categoria'];?><div class="category-label"><?=e($catAtual)?></div><?php endif;?><label class="product-row"><span class="product-info"><strong><?=e($p['nome'])?></strong><small><?=brl($p['preco'])?></small></span><span class="quantity"><span>Qtd.</span><input type="number" name="quantidade[<?=(int)$p['id']?>]" min="0" max="99" value="0" aria-label="Quantidade de <?=e($p['nome'])?>"></span></label><?php endforeach;?></div>
<label class="field-label" for="obs">Observação geral da cozinha</label><textarea id="obs" name="observacao" rows="3" maxlength="2000" placeholder="Ex.: sem cebola, molho à parte..."></textarea><button class="button primary full" type="submit">＋ Registrar comanda</button><?php endif;?></form><p class="hint">O preço do item é salvo na comanda, preservando o valor histórico mesmo que o cardápio mude.</p></div>
<div class="orders-column"><div class="section-heading"><div><span class="step">02 / ACOMPANHAMENTO</span><h2>Últimas comandas</h2></div><span class="count"><?=count($comandas)?> / 30</span></div>
<?php if(!$comandas):?><div class="panel empty large">Nenhuma comanda registrada ainda.<br><span>Use o formulário para criar o primeiro pedido.</span></div><?php else:foreach($comandas as $c):?><article class="order-card <?=$c['status']==='entregue'?'order-done':''?>"><div class="order-top"><div><span class="order-number">COMANDA #<?=(int)$c['numero']?></span><div class="order-date"><?=e(date('d/m/Y H:i',strtotime($c['data_abertura'])))?></div></div><span class="status <?=$c['status']==='entregue'?'status-done':'status-open'?>"><?=$c['status']==='entregue'?'✓ Entregue':'● Em preparo'?></span></div>
<div class="order-items"><?php foreach(($itensPor[$c['id']]??[]) as $i):?><div class="order-item"><span><b><?=(int)$i['quantidade']?>×</b> <?=e($i['nome_produto'])?></span><strong><?=brl($i['quantidade']*$i['preco_unitario'])?></strong></div><?php endforeach;?></div>
<div class="order-total"><span><?=(int)$c['unidades']?> item(ns)</span><strong><?=brl($c['total'])?></strong></div>
<?php if($c['observacao']):?><div class="observation"><b>Observação:</b> <?=nl2br(e($c['observacao']))?></div><?php endif;?>
<?php if($c['status']==='entregue'):?><div class="delivered-note">Entregue em <?=e(date('d/m/Y H:i',strtotime($c['data_entrega'])))?></div><?php else:?><div class="order-actions"><form method="post" action="index.php" onsubmit="return confirm('Marcar comanda #<?=(int)$c['numero']?> como entregue?')"><input type="hidden" name="acao" value="entregar"><input type="hidden" name="comanda_id" value="<?=(int)$c['id']?>"><button class="button done-button" type="submit">✓ Marcar entregue</button></form><details><summary>Editar observação</summary><form method="post" action="index.php" class="edit-note"><input type="hidden" name="acao" value="observacao"><input type="hidden" name="comanda_id" value="<?=(int)$c['id']?>"><textarea name="observacao" rows="2" maxlength="2000"><?=e($c['observacao'])?></textarea><button class="button secondary" type="submit">Salvar observação</button></form></details></div><?php endif;?></article><?php endforeach;endif;?></div></section>
</div>

<!-- ===================== ABA: PRODUTOS ===================== -->
<div class="tab-pane" id="aba-produtos" <?=$aba==='produtos'?'':'hidden'?>>
<section class="hero"><div><span class="eyebrow">CARDÁPIO E PREÇOS</span><h1>Cadastro de produtos</h1><p>Adicione pratos, altere valores ou retire produtos da lista de pedidos.</p></div><div class="hero-mark">♨</div></section>
<?php if($erroP):?><div class="alert error"><?=e($erroP)?></div><?php endif;?><?php if($sucessoP):?><div class="alert success"><?=e($sucessoP)?></div><?php endif;?>
<section class="product-admin-grid"><div class="panel"><div class="panel-heading"><div><span class="step"><?=$editando?'EDITAR PRODUTO':'NOVO CADASTRO'?></span><h2><?=$editando?'Editar produto':'Adicionar produto'?></h2></div></div>
<form method="post" action="index.php?aba=produtos" class="admin-form"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?=(int)($editando['id']??0)?>">
<label>Nome do produto<input name="nome" required maxlength="120" value="<?=e($editando['nome']??'')?>" placeholder="Ex.: Lasanha à bolonhesa"></label>
<label>Categoria<input name="categoria" required maxlength="60" value="<?=e($editando['categoria']??'Pratos feitos')?>" placeholder="Ex.: Pratos feitos"></label>
<label>Preço (R$)<input name="preco" type="number" required min="0" max="99999999" step="0.01" value="<?=e($editando['preco']??'')?>" placeholder="0.00"></label>
<label>Descrição <span class="muted">(opcional)</span><textarea name="descricao" rows="3" maxlength="255" placeholder="Descrição breve"><?=e($editando['descricao']??'')?></textarea></label>
<label class="check-line"><input type="checkbox" name="ativo" value="1" <?=!isset($editando['ativo'])||(int)$editando['ativo']===1?'checked':''?>> Produto disponível para novas comandas</label>
<button class="button primary full" type="submit"><?=$editando?'Salvar alterações':'Cadastrar produto'?></button><?php if($editando):?><a class="cancel-link" href="index.php?aba=produtos" data-tab="produtos">Cancelar edição</a><?php endif;?></form></div>
<div class="panel inventory"><div class="section-heading"><div><span class="step">CARDÁPIO</span><h2>Produtos cadastrados</h2></div><span class="count"><?=count($todosProdutos)?> itens</span></div>
<?php if(!$todosProdutos):?><div class="empty">Nenhum produto cadastrado.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($todosProdutos as $p):?><tr><td><strong><?=e($p['nome'])?></strong><?php if($p['descricao']):?><small class="desc-cell"><?=e($p['descricao'])?></small><?php endif;?></td><td><?=e($p['categoria'])?></td><td class="price-cell"><?=brl($p['preco'])?></td><td><span class="status <?=$p['ativo']?'status-done':'status-off'?>"><?=$p['ativo']?'Ativo':'Inativo'?></span></td><td><div class="table-actions"><a class="small-link" href="index.php?aba=produtos&amp;editar=<?=(int)$p['id']?>">Editar</a><form method="post" action="index.php?aba=produtos" onsubmit="return confirm('Alterar disponibilidade deste produto?')"><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="text-button" type="submit"><?=$p['ativo']?'Desativar':'Ativar'?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></section>
</div>

<!-- ===================== ABA: USUÁRIOS ===================== -->
<div class="tab-pane" id="aba-usuarios" <?=$aba==='usuarios'?'':'hidden'?>>
<section class="hero"><div><span class="eyebrow">EQUIPE E ACESSOS</span><h1>Cadastro de usuários</h1><p>Cadastre a equipe do bistrô, defina o perfil de cada pessoa e controle quem está ativo.</p></div><div class="hero-mark">👤</div></section>
<?php if($erroU):?><div class="alert error"><?=e($erroU)?></div><?php endif;?><?php if($sucessoU):?><div class="alert success"><?=e($sucessoU)?></div><?php endif;?>
<?php if(!$semTabelaU):?>
<section class="product-admin-grid"><div class="panel"><div class="panel-heading"><div><span class="step"><?=$modoEdicaoU?'EDITAR USUÁRIO':'NOVO CADASTRO'?></span><h2><?=$modoEdicaoU?'Editar usuário':'Adicionar usuário'?></h2></div></div>
<form method="post" action="index.php?aba=usuarios" class="admin-form" autocomplete="off"><input type="hidden" name="acao" value="salvar_usuario"><input type="hidden" name="id" value="<?=(int)($formU['id']??0)?>">
<label>Nome completo<input name="nome" required minlength="3" maxlength="120" value="<?=e($formU['nome'])?>" placeholder="Ex.: Maria da Silva" autocomplete="name"></label>
<label>E-mail<input name="email" type="email" required maxlength="150" value="<?=e($formU['email'])?>" placeholder="nome@exemplo.com" autocomplete="email"></label>
<div class="form-row">
<label>Telefone <span class="muted">(opcional)</span><input id="u-tel" name="telefone" type="tel" inputmode="numeric" maxlength="15" value="<?=e(tel_fmt($formU['telefone']))?>" placeholder="(49) 99999-9999" autocomplete="tel"></label>
<label>Perfil<select name="perfil" required><?php foreach($perfis as $chave=>$rotulo):?><option value="<?=e($chave)?>" <?=($formU['perfil']===$chave)?'selected':''?>><?=e($rotulo)?></option><?php endforeach;?></select></label>
</div>
<div class="form-row">
<label>Senha<input id="u-senha" name="senha" type="password" minlength="8" maxlength="72" <?=$modoEdicaoU?'':'required'?> autocomplete="new-password" placeholder="Mínimo 8 caracteres"></label>
<label>Confirmar senha<input id="u-senha2" name="senha2" type="password" minlength="8" maxlength="72" <?=$modoEdicaoU?'':'required'?> autocomplete="new-password" placeholder="Repita a senha"></label>
</div>
<span class="form-help"><?=$modoEdicaoU?'Deixe os campos de senha em branco para manter a senha atual. ':''?>A senha é armazenada de forma criptografada (hash).</span>
<label class="check-line"><input type="checkbox" id="u-ver" > Mostrar senhas digitadas</label>
<label class="check-line"><input type="checkbox" name="ativo" value="1" <?=(int)$formU['ativo']===1?'checked':''?>> Usuário ativo</label>
<button class="button primary full" type="submit"><?=$modoEdicaoU?'Salvar alterações':'Cadastrar usuário'?></button><?php if($modoEdicaoU):?><a class="cancel-link" href="index.php?aba=usuarios">Cancelar edição</a><?php endif;?></form></div>
<div class="panel inventory"><div class="section-heading"><div><span class="step">EQUIPE</span><h2>Usuários cadastrados</h2></div><span class="count"><?=count($usuarios)?> usuário(s)</span></div>
<?php if(!$usuarios):?><div class="empty">Nenhum usuário cadastrado ainda.<br><span>Use o formulário ao lado para criar o primeiro.</span></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Perfil</th><th>Telefone</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($usuarios as $u):?><tr><td><strong><?=e($u['nome'])?></strong><small class="mail-cell"><?=e($u['email'])?></small><small class="mail-cell">Desde <?=e(date('d/m/Y',strtotime($u['criado_em'])))?></small></td><td><span class="status status-role"><?=e($perfis[$u['perfil']]??$u['perfil'])?></span></td><td class="price-cell"><?=$u['telefone']?e(tel_fmt($u['telefone'])):'—'?></td><td><span class="status <?=$u['ativo']?'status-done':'status-off'?>"><?=$u['ativo']?'Ativo':'Inativo'?></span></td><td><div class="table-actions"><a class="small-link" href="index.php?aba=usuarios&amp;editar_usuario=<?=(int)$u['id']?>">Editar</a><form method="post" action="index.php?aba=usuarios" onsubmit="return confirm('Alterar o status deste usuário?')"><input type="hidden" name="acao" value="alternar_usuario"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="text-button" type="submit"><?=$u['ativo']?'Desativar':'Ativar'?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></section>
<?php endif;?>
</div>

<footer>Projeto didático • UC2 — Práticas DevOps em Projetos Web <span>PHP + MySQL</span></footer></main>
<script>
// Troca de abas sem recarregar. Sem JavaScript, os links (?aba=...) continuam funcionando.
(function(){
 var abas=['comandas','produtos','usuarios'];
 function mostrar(nome,atualizarUrl){
  if(abas.indexOf(nome)<0) nome='comandas';
  abas.forEach(function(a){
   document.getElementById('aba-'+a).hidden=(a!==nome);
   var t=document.querySelector('#tabs [data-tab="'+a+'"]');
   t.classList.toggle('nav-active',a===nome);
   t.setAttribute('aria-selected',a===nome?'true':'false');
  });
  if(atualizarUrl) history.replaceState(null,'','#'+nome);
 }
 document.addEventListener('click',function(ev){
  var l=ev.target.closest('a[data-tab]');
  if(!l) return;
  // "Cancelar edição" precisa recarregar para limpar o formulário.
  if(l.classList.contains('cancel-link')) return;
  ev.preventDefault(); mostrar(l.getAttribute('data-tab'),true); window.scrollTo(0,0);
 });
 // Ao abrir com #produtos (ex.: link salvo), respeita o hash, exceto quando o servidor já escolheu a aba (?aba=, ?editar=).
 var q=location.search;
 if(location.hash && q.indexOf('aba=')<0 && q.indexOf('editar=')<0 && q.indexOf('editar_usuario=')<0 && q.indexOf('ok=')<0 && q.indexOf('uok=')<0) mostrar(location.hash.slice(1),false);
 window.addEventListener('hashchange',function(){ if(location.hash) mostrar(location.hash.slice(1),false); });
})();
// Aba Usuários: mostrar/ocultar senha, máscara de telefone e conferência da confirmação.
(function(){
 var ver=document.getElementById('u-ver'),s1=document.getElementById('u-senha'),s2=document.getElementById('u-senha2'),tel=document.getElementById('u-tel');
 if(ver&&s1&&s2) ver.addEventListener('change',function(){ s1.type=s2.type=ver.checked?'text':'password'; });
 if(s1&&s2){ var conf=function(){ s2.setCustomValidity(s2.value!==s1.value?'As senhas não conferem.':''); }; s1.addEventListener('input',conf); s2.addEventListener('input',conf); }
 if(tel) tel.addEventListener('input',function(){
  var d=tel.value.replace(/\D/g,'').slice(0,11), r=d;
  if(d.length>10) r='('+d.slice(0,2)+') '+d.slice(2,7)+'-'+d.slice(7);
  else if(d.length>6) r='('+d.slice(0,2)+') '+d.slice(2,6)+'-'+d.slice(6);
  else if(d.length>2) r='('+d.slice(0,2)+') '+d.slice(2);
  else if(d.length>0) r='('+d;
  tel.value=r;
 });
})();
</script>
</body></html>
