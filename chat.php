<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','manager','employee']);

if (isset($_GET['messages'])) {
    $conversationId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $afterId=filter_var($_GET['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if (!$conversationId || $afterId===false) fail(400,'Invalid chat request.');
    if (!one('SELECT conversation_id FROM chat_participants WHERE conversation_id=? AND user_id=?',[$conversationId,$user['id']])) fail(403,'You are not a member of this chat.');
    $messages=rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id LIMIT 100',[$conversationId,$afterId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['messages'=>$messages],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
if (isset($_GET['conversations'])) {
    $conversations=rows("SELECT c.id,c.conversation_type,c.title,
        (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=c.id) AS last_message_id,
        (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_members
        FROM chat_conversations c JOIN chat_participants mine ON mine.conversation_id=c.id AND mine.user_id=?
        ORDER BY COALESCE(last_message_id,c.id) DESC,c.id DESC",[$user['id'],$user['id']]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['conversations'=>$conversations],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}

$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        $action=$_POST['action']??'';
        if ($action==='create_conversation') {
            $type=$_POST['conversation_type']??'';
            if (!in_array($type,['group','private'],true)) throw new InvalidArgumentException('Choose a group or private chat.');
            $rawIds=$_POST['user_ids']??[];
            if (!is_array($rawIds) || count($rawIds)>100) throw new InvalidArgumentException('Choose valid chat participants.');
            $participantIds=[];
            foreach ($rawIds as $rawId) $participantIds[]=positive_id($rawId);
            $participantIds=array_values(array_unique($participantIds));
            if (in_array((int)$user['id'],$participantIds,true)) throw new InvalidArgumentException('You are already included in this chat.');
            if ($type==='private' && count($participantIds)!==1) throw new InvalidArgumentException('Choose exactly one person for a private chat.');
            if ($type==='group' && count($participantIds)<1) throw new InvalidArgumentException('Choose at least one person for a group chat.');
            $placeholders=implode(',',array_fill(0,count($participantIds),'?'));
            $validUsers=rows("SELECT id FROM users WHERE id IN ($placeholders) AND role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL",$participantIds);
            if (count($validUsers)!==count($participantIds)) throw new InvalidArgumentException('One or more selected users are unavailable.');
            $title=$type==='group'?optional_text('title',120):'';
            db()->beginTransaction();
            query('INSERT INTO chat_conversations (conversation_type,title,created_by) VALUES (?,?,?)',[$type,$title?:null,$user['id']]);
            $conversationId=(int)db()->lastInsertId();
            foreach (array_merge([(int)$user['id']],$participantIds) as $participantId) {
                query('INSERT INTO chat_participants (conversation_id,user_id,added_by) VALUES (?,?,?)',[$conversationId,$participantId,$user['id']]);
            }
            db()->commit();
            redirect('chat.php?id='.$conversationId);
        }
        if ($action==='send_message') {
            $conversationId=positive_id($_POST['conversation_id']??null);
            if (!one('SELECT conversation_id FROM chat_participants WHERE conversation_id=? AND user_id=?',[$conversationId,$user['id']])) throw new InvalidArgumentException('You are not a member of this chat.');
            $body=text_input('body',5000);
            query('INSERT INTO chat_messages (conversation_id,sender_id,body) VALUES (?,?,?)',[$conversationId,$user['id'],$body]);
            query('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$conversationId]);
            redirect('chat.php?id='.$conversationId.'#chat-latest');
        }
        throw new InvalidArgumentException('Invalid chat action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error=mutation_error($exception);
    }
}

$conversations=rows("SELECT c.id,c.conversation_type,c.title,c.created_at,
    (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=c.id) AS last_message_id,
    (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_members
    FROM chat_conversations c JOIN chat_participants mine ON mine.conversation_id=c.id AND mine.user_id=?
    ORDER BY COALESCE(last_message_id,c.id) DESC,c.id DESC",[$user['id'],$user['id']]);
$selectedId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if (!$selectedId && $conversations) $selectedId=(int)$conversations[0]['id'];
$selected=$selectedId?one('SELECT c.* FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=?',[$selectedId,$user['id']]):null;
if ($selectedId && !$selected) fail(404,'Chat not found.');
$messages=$selected?rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 100',[$selectedId]):[];
$messages=array_reverse($messages);
$people=rows("SELECT id,full_name,role FROM users WHERE id<>? AND role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL ORDER BY full_name",[$user['id']]);
page_start('Chat','chat.php'); error_message($error);
?>
<div class="chat-layout">
    <section class="panel chat-start-panel">
        <h2>Start a chat</h2>
        <form method="post" class="chat-start-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_conversation">
            <div class="field"><label for="conversation_type">Chat type</label><select id="conversation_type" name="conversation_type"><option value="private">Private chat</option><option value="group">Group chat</option></select></div>
            <div class="field" id="chat-title-field" hidden><label for="chat-title">Group name (optional)</label><input id="chat-title" name="title" maxlength="120" placeholder="Example: Design team"></div>
            <div class="field"><label for="chat-users">People</label><select id="chat-users" name="user_ids[]" multiple size="7" required><?php foreach ($people as $person): ?><option value="<?= (int)$person['id'] ?>"><?= h($person['full_name']) ?> · <?= h(ucfirst($person['role'])) ?></option><?php endforeach; ?></select><p class="help">Select one person for a private chat. Select one or more for a group.</p></div>
            <button class="primary" type="submit">Start chat</button>
        </form>
    </section>
    <section class="panel chat-list-panel"><h2>Your chats</h2><?php if (!$conversations): ?><p class="muted">Start a private or group chat to begin.</p><?php endif; ?><div class="chat-list"><?php foreach ($conversations as $conversation): $label=$conversation['conversation_type']==='group'?($conversation['title']?:$conversation['other_members']):$conversation['other_members']; ?><a class="chat-list-item <?= (int)$conversation['id']===$selectedId?'active':'' ?>" data-chat-id="<?= (int)$conversation['id'] ?>" href="chat.php?id=<?= (int)$conversation['id'] ?>"><strong><?= h($label?:'Chat') ?></strong><small><?= $conversation['conversation_type']==='group'?'Group':'Private' ?> chat</small></a><?php endforeach; ?></div></section>
    <section class="panel chat-room-panel" <?= $selected?'data-chat-room data-conversation="'.(int)$selectedId.'"':' ' ?>>
        <?php if (!$selected): ?><div class="chat-empty"><h2>Your conversation appears here</h2><p class="muted">Choose a chat or start a new one.</p></div><?php else: $otherMembers=one("SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS names FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id<>?", [$selectedId,$user['id']])['names']??''; $roomTitle=$selected['conversation_type']==='group'?($selected['title']?:$otherMembers):$otherMembers; ?>
            <div class="chat-room-heading"><div><h2><?= h($roomTitle?:'Chat') ?></h2><p class="muted"><?= $selected['conversation_type']==='group'?'Group conversation':'Private conversation' ?></p></div></div>
            <div class="chat-messages" id="chat-messages" aria-live="polite"><?php foreach ($messages as $message): ?><article class="chat-message <?= (int)$message['sender_id']===(int)$user['id']?'own':'' ?>" data-message-id="<?= (int)$message['id'] ?>"><div><strong><?= h($message['sender_name']) ?></strong><time><?= h(date('d-m-Y g:i A',strtotime($message['created_at']))) ?></time></div><p><?= h($message['body']) ?></p></article><?php endforeach; ?><span id="chat-latest"></span></div>
            <form method="post" class="chat-compose"><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>"><label class="sr-only" for="chat-body">Message</label><textarea id="chat-body" name="body" rows="2" maxlength="5000" placeholder="Write a message…" required></textarea><button class="primary" type="submit">Send</button></form>
        <?php endif; ?>
    </section>
</div>
<script>
(() => {
    const type=document.getElementById('conversation_type'), title=document.getElementById('chat-title-field');
    if(type&&title) type.addEventListener('change',()=>{title.hidden=type.value!=='group';});
    const room=document.querySelector('[data-chat-room]'), box=document.getElementById('chat-messages');
    async function refreshConversations(){try{const response=await fetch('chat.php?conversations=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();const list=document.querySelector('.chat-list');if(!list)return;payload.conversations.forEach(conversation=>{if(list.querySelector(`[data-chat-id="${conversation.id}"]`))return;const link=document.createElement('a');link.className='chat-list-item';link.dataset.chatId=conversation.id;link.href=`chat.php?id=${conversation.id}`;const name=document.createElement('strong');name.textContent=conversation.conversation_type==='group'?(conversation.title||conversation.other_members||'Group chat'):(conversation.other_members||'Private chat');const kind=document.createElement('small');kind.textContent=conversation.conversation_type==='group'?'Group chat':'Private chat';link.append(name,kind);list.prepend(link);});}catch(_){}}
    window.setInterval(refreshConversations,5000);
    if(!room||!box)return;
    let lastId=Math.max(0,...Array.from(box.querySelectorAll('[data-message-id]'),item=>Number(item.dataset.messageId)||0));
    const append=message=>{
        const item=document.createElement('article'); item.className='chat-message'+(Number(message.sender_id)===<?= (int)$user['id'] ?>?' own':''); item.dataset.messageId=message.id;
        const meta=document.createElement('div'),name=document.createElement('strong'),time=document.createElement('time'),body=document.createElement('p');
        name.textContent=message.sender_name; time.textContent=message.created_at; body.textContent=message.body; meta.append(name,time); item.append(meta,body); box.insertBefore(item,document.getElementById('chat-latest')); lastId=Math.max(lastId,Number(message.id)||0); box.scrollTop=box.scrollHeight;
    };
    async function refresh(){try{const response=await fetch(`chat.php?messages=1&id=${room.dataset.conversation}&after=${lastId}`,{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();payload.messages.forEach(append);}catch(_){}}
    box.scrollTop=box.scrollHeight; window.setInterval(refresh,3000);
})();
</script>
<?php page_end(); ?>
