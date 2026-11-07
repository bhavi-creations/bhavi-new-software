<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','manager','employee']);

function chat_attachments_from_rows(array $messages): array
{
    if (!$messages) return [];
    $ids=array_map(static fn($message)=>(int)$message['id'],$messages);
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $attachments=rows("SELECT id,message_id,original_file_name,mime_type,file_size_bytes FROM chat_attachments WHERE message_id IN ($placeholders) ORDER BY id",$ids);
    $byMessage=[];
    foreach ($attachments as $attachment) $byMessage[(int)$attachment['message_id']][]=$attachment;
    return $byMessage;
}

if (isset($_GET['messages'])) {
    $conversationId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $afterId=filter_var($_GET['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if (!$conversationId || $afterId===false) fail(400,'Invalid chat request.');
    if (!one('SELECT conversation_id FROM chat_participants WHERE conversation_id=? AND user_id=?',[$conversationId,$user['id']])) fail(403,'You are not a member of this chat.');
    $messages=rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id LIMIT 100',[$conversationId,$afterId]);
    $files=chat_attachments_from_rows($messages);
    foreach ($messages as &$message) $message['files']=$files[(int)$message['id']]??[];
    unset($message);
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
    $storedFiles=[];
    try {
        $action=$_POST['action']??'';
        if ($action==='create_conversation') {
            $type=$_POST['conversation_type']??'';
            if (!in_array($type,['group','private'],true)) throw new InvalidArgumentException('Choose a group or private chat.');
            $rawIds=$_POST['user_ids']??[];
            if (!is_array($rawIds)) throw new InvalidArgumentException('Choose valid chat participants.');
            $participantIds=[];
            foreach ($rawIds as $rawId) $participantIds[]=positive_id($rawId);
            $participantIds=array_values(array_unique($participantIds));
            if (in_array((int)$user['id'],$participantIds,true)) throw new InvalidArgumentException('You are already included in this chat.');
            if ($type==='private' && count($participantIds)!==1) throw new InvalidArgumentException('Choose exactly one person for a private chat.');
            if ($type==='group' && count($participantIds)<1) throw new InvalidArgumentException('Choose at least one person for a group chat.');
            $placeholders=implode(',',array_fill(0,count($participantIds),'?'));
            $validUsers=rows("SELECT id FROM users WHERE id IN ($placeholders) AND role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL",$participantIds);
            if (count($validUsers)!==count($participantIds)) throw new InvalidArgumentException('One or more selected users are unavailable.');
            $title=$type==='group'?(isset($_POST['title'])?text_input('title',120,false):''):'';
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
            $body=text_input('body',5000,false);
            $upload=$_FILES['files']??null; $files=[]; $maxBytes=5*1024*1024*1024; $totalBytes=0;
            if ($upload && isset($upload['name'])) {
                if (!is_array($upload['name']) || count($upload['name'])>20) throw new InvalidArgumentException('Attach up to 20 files in one message.');
                foreach ($upload['name'] as $index=>$originalName) {
                    $uploadError=(int)($upload['error'][$index]??UPLOAD_ERR_NO_FILE);
                    if ($uploadError===UPLOAD_ERR_NO_FILE) continue;
                    if ($uploadError===UPLOAD_ERR_INI_SIZE || $uploadError===UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('A file is larger than the server upload limit. The app allows up to 5 GB per message; hosting limits may be lower.');
                    if ($uploadError!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'][$index]??'')) throw new InvalidArgumentException('A file upload failed. Check the server upload limits and try again.');
                    $size=filesize($upload['tmp_name'][$index]);
                    if ($size===false || $size<0 || $size>$maxBytes) throw new InvalidArgumentException('Each attached file must be 5 GB or smaller.');
                    $totalBytes+=$size;
                    if ($totalBytes>$maxBytes) throw new InvalidArgumentException('Attachments in one message must total 5 GB or less.');
                    $name=basename(str_replace('\\','/',(string)$originalName));
                    $name=preg_replace('/[\\x00-\\x1F\\x7F]/u','',$name)?:'chat-file';
                    if (strlen($name)>255) $name=substr($name,0,255);
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name'][$index])?:'application/octet-stream';
                    $files[]=['tmp'=>$upload['tmp_name'][$index],'name'=>$name,'size'=>$size,'mime'=>substr($mime,0,100)];
                }
            }
            if ($body==='' && !$files) throw new InvalidArgumentException('Write a message or choose a file to send.');
            $directory=dirname(__DIR__).'/storage/chat';
            if ($files && !is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new InvalidArgumentException('Unable to create secure chat file storage.');
            if ($files && !is_writable($directory)) throw new InvalidArgumentException('Secure chat file storage is not writable by PHP.');
            db()->beginTransaction();
            query('INSERT INTO chat_messages (conversation_id,sender_id,body) VALUES (?,?,?)',[$conversationId,$user['id'],$body]);
            $messageId=(int)db()->lastInsertId();
            foreach ($files as $file) {
                $relative='storage/chat/'.bin2hex(random_bytes(24)).'.bin';
                $absolute=dirname(__DIR__).'/'.$relative;
                if (!move_uploaded_file($file['tmp'],$absolute)) throw new InvalidArgumentException('A file could not be stored. Check available disk space and chat storage permissions.');
                $storedFiles[]=$absolute;
                query('INSERT INTO chat_attachments (message_id,original_file_name,storage_path,mime_type,file_size_bytes,uploaded_by) VALUES (?,?,?,?,?,?)',[$messageId,$file['name'],$relative,$file['mime'],$file['size'],$user['id']]);
            }
            query('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$conversationId]);
            db()->commit();
            redirect('chat.php?id='.$conversationId.'#chat-latest');
        }
        throw new InvalidArgumentException('Invalid chat action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
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
$filesByMessage=chat_attachments_from_rows($messages);
$people=rows("SELECT id,full_name,role FROM users WHERE id<>? AND role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL ORDER BY full_name",[$user['id']]);
page_start('Chat','chat.php'); error_message($error);
?>
<div class="chat-layout">
    <section class="panel chat-start-panel">
        <h2>Start a chat</h2>
        <form method="post" class="chat-start-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_conversation">
            <div class="field"><label for="conversation_type">Chat type</label><select id="conversation_type" name="conversation_type"><option value="private">Private chat</option><option value="group">Group chat</option></select></div>
            <div class="field" id="chat-title-field" hidden><label for="chat-title">Group name (optional)</label><input id="chat-title" name="title" maxlength="120" placeholder="Example: Design team"></div>
            <div class="field"><label for="chat-users">People</label><select id="chat-users" name="user_ids[]" size="7" required><?php foreach ($people as $person): ?><option value="<?= (int)$person['id'] ?>"><?= h($person['full_name']) ?> · <?= h(ucfirst($person['role'])) ?></option><?php endforeach; ?></select><p class="help" id="chat-people-help">Private chat: choose one person.</p></div>
            <button class="primary" type="submit">Start chat</button>
        </form>
    </section>
    <section class="panel chat-list-panel"><h2>Your chats</h2><?php if (!$conversations): ?><p class="muted">Start a private or group chat to begin.</p><?php endif; ?><div class="chat-list"><?php foreach ($conversations as $conversation): $label=$conversation['conversation_type']==='group'?($conversation['title']?:$conversation['other_members']):$conversation['other_members']; ?><a class="chat-list-item <?= (int)$conversation['id']===$selectedId?'active':'' ?>" data-chat-id="<?= (int)$conversation['id'] ?>" href="chat.php?id=<?= (int)$conversation['id'] ?>"><strong><?= h($label?:'Chat') ?></strong><small><?= $conversation['conversation_type']==='group'?'Group':'Private' ?> chat</small></a><?php endforeach; ?></div></section>
    <section class="panel chat-room-panel" <?= $selected?'data-chat-room data-conversation="'.(int)$selectedId.'"':' ' ?>>
        <?php if (!$selected): ?><div class="chat-empty"><h2>Your conversation appears here</h2><p class="muted">Choose a chat or start a new one.</p></div><?php else: $otherMembers=one("SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS names FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id<>?", [$selectedId,$user['id']])['names']??''; $roomTitle=$selected['conversation_type']==='group'?($selected['title']?:$otherMembers):$otherMembers; ?>
            <div class="chat-room-heading"><div><h2><?= h($roomTitle?:'Chat') ?></h2><p class="muted"><?= $selected['conversation_type']==='group'?'Group conversation':'Private conversation' ?></p></div></div>
            <div class="chat-messages" id="chat-messages" aria-live="polite"><?php foreach ($messages as $message): ?><article class="chat-message <?= (int)$message['sender_id']===(int)$user['id']?'own':'' ?>" data-message-id="<?= (int)$message['id'] ?>"><div><strong><?= h($message['sender_name']) ?></strong><time><?= h(date('d-m-Y g:i A',strtotime($message['created_at']))) ?></time></div><?php if ($message['body']!==''): ?><p><?= h($message['body']) ?></p><?php endif; ?><?php foreach ($filesByMessage[(int)$message['id']]??[] as $attachment): ?><a class="chat-attachment" href="download-chat-attachment.php?id=<?= (int)$attachment['id'] ?>"><?= icon('download') ?> <?= h($attachment['original_file_name']) ?> <small><?= h(chat_file_size_label((int)$attachment['file_size_bytes'])) ?></small></a><?php endforeach; ?></article><?php endforeach; ?><span id="chat-latest"></span></div>
            <form method="post" enctype="multipart/form-data" class="chat-compose" data-chat-compose><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>"><label class="sr-only" for="chat-body">Message</label><textarea id="chat-body" name="body" rows="2" maxlength="5000" placeholder="Write a message…"></textarea><label class="chat-attach-button" for="chat-files">Attach files</label><input id="chat-files" name="files[]" type="file" multiple><button class="primary" type="submit">Send</button><progress id="chat-upload-progress" max="100" value="0" hidden></progress><small id="chat-upload-status" role="status"></small><p class="help">Up to 5 GB total per message. Large uploads depend on your hosting and network limits.</p></form>
        <?php endif; ?>
    </section>
</div>
<script>
(() => {
    const type=document.getElementById('conversation_type'), title=document.getElementById('chat-title-field');
    const people=document.getElementById('chat-users'), peopleHelp=document.getElementById('chat-people-help');
    if(type&&title) type.addEventListener('change',()=>{title.hidden=type.value!=='group';if(people){people.multiple=type.value==='group';if(!people.multiple&&people.selectedOptions.length>1)people.selectedIndex=-1;}if(peopleHelp)peopleHelp.textContent=type.value==='group'?'Select as many people as you need for this group.':'Private chat: choose one person.';});
    const room=document.querySelector('[data-chat-room]'), box=document.getElementById('chat-messages');
    async function refreshConversations(){try{const response=await fetch('chat.php?conversations=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();const list=document.querySelector('.chat-list');if(!list)return;payload.conversations.forEach(conversation=>{if(list.querySelector(`[data-chat-id="${conversation.id}"]`))return;const link=document.createElement('a');link.className='chat-list-item';link.dataset.chatId=conversation.id;link.href=`chat.php?id=${conversation.id}`;const name=document.createElement('strong');name.textContent=conversation.conversation_type==='group'?(conversation.title||conversation.other_members||'Group chat'):(conversation.other_members||'Private chat');const kind=document.createElement('small');kind.textContent=conversation.conversation_type==='group'?'Group chat':'Private chat';link.append(name,kind);list.prepend(link);});}catch(_){}}
    window.setInterval(refreshConversations,5000);
    if(!room||!box)return;
    let lastId=Math.max(0,...Array.from(box.querySelectorAll('[data-message-id]'),item=>Number(item.dataset.messageId)||0));
    const append=message=>{
        const item=document.createElement('article'); item.className='chat-message'+(Number(message.sender_id)===<?= (int)$user['id'] ?>?' own':''); item.dataset.messageId=message.id;
        const meta=document.createElement('div'),name=document.createElement('strong'),time=document.createElement('time'),body=document.createElement('p');
        name.textContent=message.sender_name; time.textContent=message.created_at; meta.append(name,time); item.append(meta); if(message.body){body.textContent=message.body;item.append(body);} (message.files||[]).forEach(file=>{const link=document.createElement('a'),size=document.createElement('small');link.className='chat-attachment';link.href=`download-chat-attachment.php?id=${file.id}`;link.append(document.createTextNode(`⬇ ${file.original_file_name} `));size.textContent=file.file_size_bytes>=1073741824?`${(file.file_size_bytes/1073741824).toFixed(2)} GB`:`${(file.file_size_bytes/1048576).toFixed(1)} MB`;link.append(size);item.append(link);}); box.insertBefore(item,document.getElementById('chat-latest')); lastId=Math.max(lastId,Number(message.id)||0); box.scrollTop=box.scrollHeight;
    };
    async function refresh(){try{const response=await fetch(`chat.php?messages=1&id=${room.dataset.conversation}&after=${lastId}`,{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();payload.messages.forEach(append);}catch(_){}}
    box.scrollTop=box.scrollHeight; window.setInterval(refresh,3000);
    const compose=document.querySelector('[data-chat-compose]');
    if(compose)compose.addEventListener('submit',event=>{
        event.preventDefault();const xhr=new XMLHttpRequest(),progress=document.getElementById('chat-upload-progress'),status=document.getElementById('chat-upload-status'),button=compose.querySelector('button[type="submit"]');
        xhr.open('POST',compose.action||window.location.href);button.disabled=true;progress.hidden=false;status.textContent='Uploading…';
        xhr.upload.onprogress=event=>{if(event.lengthComputable){progress.value=Math.round(event.loaded/event.total*100);status.textContent=`Uploading… ${progress.value}%`;}};
        xhr.onload=()=>{const responseDoc=new DOMParser().parseFromString(xhr.responseText,'text/html'),serverError=responseDoc.querySelector('.alert.error');if(serverError){button.disabled=false;progress.hidden=true;status.textContent=serverError.textContent.trim();return;}if(xhr.status>=200&&xhr.status<400){window.location.href=xhr.responseURL||window.location.href;}else{button.disabled=false;progress.hidden=true;status.textContent=xhr.status===413?'Upload exceeds the server request size limit.':'Message or file could not be sent. Please try again.';}};
        xhr.onerror=()=>{button.disabled=false;progress.hidden=true;status.textContent='Upload failed. Check the connection and try again.';};xhr.send(new FormData(compose));
    });
})();
</script>
<?php page_end(); ?>
