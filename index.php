<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function brl($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function tam(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s,'UTF-8') : (int)preg_match_all('/./us',$s); }

// Aba ativa: "comandas" (padrão) ou "produtos". Funciona mesmo sem JavaScript (?aba=produtos).
$aba = (($_GET['aba'] ?? '') === 'produtos' || isset($_GET['editar'])) ? 'produtos' : 'comandas';
$erroC=''; $sucessoC=''; $erroP=''; $sucessoP=''; $editando=null;

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
  }
 } catch(Throwable $ex) {
  if($pdo->inTransaction()) $pdo->rollBack();
  error_log('Erro: '.$ex->getMessage());
  if($aba==='produtos') $erroP='Não foi possível salvar a alteração.';
  else $erroC='Não foi possível concluir a operação. Confira banco e configuração.';
 }
}

if(isset($_GET['criada'])) $sucessoC='Comanda #'.(int)$_GET['criada'].' registrada com sucesso.';
if(isset($_GET['entregue'])) $sucessoC='Comanda marcada como entregue.';
if(isset($_GET['obs'])) $sucessoC='Observação atualizada.';
$okMsgs=['atualizado'=>'Produto atualizado.','cadastrado'=>'Produto cadastrado.','alternado'=>'Disponibilidade atualizada.'];
if(isset($_GET['ok'],$okMsgs[$_GET['ok']])) $sucessoP=$okMsgs[$_GET['ok']];
if(isset($_GET['editar'])){$id=filter_var($_GET['editar'],FILTER_VALIDATE_INT);if($id){$s=$pdo->prepare('SELECT * FROM produtos WHERE id=?');$s->execute([$id]);$editando=$s->fetch()?:null;}}

$todosProdutos=$pdo->query('SELECT * FROM produtos ORDER BY categoria,nome')->fetchAll();
$produtosAtivos=array_values(array_filter($todosProdutos,fn($p)=>(int)$p['ativo']===1));
$comandas=$pdo->query("SELECT c.*,COALESCE(SUM(i.quantidade*i.preco_unitario),0) total,COALESCE(SUM(i.quantidade),0) unidades FROM comandas c LEFT JOIN itens_comanda i ON i.comanda_id=c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 30")->fetchAll();
$itensPor=[];
if($comandas){$ids=array_map(fn($c)=>(int)$c['id'],$comandas);$ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT * FROM itens_comanda WHERE comanda_id IN ($ph) ORDER BY id");$s->execute($ids);foreach($s->fetchAll() as $i)$itensPor[$i['comanda_id']][]=$i;}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bistrô Sabor da Vila</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="topbar"><div class="wrap topbar-inner"><a class="brand" href="index.php"><span class="brand-icon">SV</span><span>Bistrô <b>Sabor da Vila</b><small>PAINEL DE COMANDAS</small></span></a>
<nav id="tabs" role="tablist"><a href="index.php?aba=comandas" data-tab="comandas" role="tab" class="<?=$aba==='comandas'?'nav-active':''?>">Comandas</a><a href="index.php?aba=produtos" data-tab="produtos" role="tab" class="<?=$aba==='produtos'?'nav-active':''?>">Produtos</a></nav></div></header>
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

<footer>Projeto didático • UC2 — Práticas DevOps em Projetos Web <span>PHP + MySQL</span></footer></main>
<script>
// Troca de abas sem recarregar. Sem JavaScript, os links (?aba=...) continuam funcionando.
(function(){
 var abas=['comandas','produtos'];
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
 if(location.hash && q.indexOf('aba=')<0 && q.indexOf('editar=')<0 && q.indexOf('ok=')<0) mostrar(location.hash.slice(1),false);
 window.addEventListener('hashchange',function(){ if(location.hash) mostrar(location.hash.slice(1),false); });
})();
</script>
</body></html>
