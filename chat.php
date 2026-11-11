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
    (SELECT m.body FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
    (SELECT m.created_at FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
    (SELECT MIN(p.user_id) FROM chat_participants p WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_id,
    (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_members
    FROM chat_conversations c JOIN chat_participants mine ON mine.conversation_id=c.id AND mine.user_id=?
    ORDER BY COALESCE(last_message_id,c.id) DESC,c.id DESC",[$user['id'],$user['id'],$user['id']]);
$selectedId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if (!$selectedId && $conversations) $selectedId=(int)$conversations[0]['id'];
$selected=$selectedId?one('SELECT c.* FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=?',[$selectedId,$user['id']]):null;
if ($selectedId && !$selected) fail(404,'Chat not found.');
$messages=$selected?rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 100',[$selectedId]):[];
$messages=array_reverse($messages);
$filesByMessage=chat_attachments_from_rows($messages);
$people=rows("SELECT u.id,u.full_name,u.role,d.name AS department_name FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.id<>? AND u.role IN ('admin','manager','employee') AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name",[$user['id']]);
$initials=static function(?string $name): string { $o=''; foreach (array_slice(preg_split('/\s+/',trim((string)$name))?:[],0,2) as $p) $o.=mb_strtoupper(mb_substr($p,0,1)); return $o?:'?'; };
$profileOf=static fn(int $id)=>one("SELECT u.id,u.full_name,u.username,u.role,ep.designation,d.name AS department_name FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.id=?",[$id]);
$shortDate=static function(?string $at): string { if (!$at) return ''; $d=date('Y-m-d',strtotime($at)); return $d===today()?date('g:i A',strtotime($at)):($d===date('Y-m-d',strtotime('-1 day'))?'Yesterday':date('j M',strtotime($at))); };
$dayLabel=static function(string $at): string { $d=date('Y-m-d',strtotime($at)); return $d===today()?'Today':($d===date('Y-m-d',strtotime('-1 day'))?'Yesterday':date('l, j M Y',strtotime($at))); };
$isStaff=in_array($user['role'],['admin','manager'],true);
$roomTitle=''; $roomProfile=null; $members=[]; $sharedFiles=[];
if ($selected) {
    $members=rows("SELECT u.id,u.full_name,u.role FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id<>? ORDER BY u.full_name",[$selectedId,$user['id']]);
    $memberNames=implode(', ',array_column($members,'full_name'));
    $roomTitle=$selected['conversation_type']==='group'?($selected['title']?:$memberNames):$memberNames;
    if ($selected['conversation_type']==='private' && $members) $roomProfile=$profileOf((int)$members[0]['id']);
    $sharedFiles=rows('SELECT a.id,a.original_file_name,a.file_size_bytes,a.created_at FROM chat_attachments a JOIN chat_messages m ON m.id=a.message_id WHERE m.conversation_id=? ORDER BY a.id DESC LIMIT 12',[$selectedId]);
}
$roomSub=$roomProfile?implode(' · ',array_filter([$roomProfile['department_name'],$roomProfile['designation']?:ucfirst($roomProfile['role'])])):($selected?count($members)+1 .' members':'');
page_start('Chat','chat.php'); error_message($error);
?>
<div class="emp-page tc-page">
<header class="emp-header"><div><p class="emp-crumb">Dashboard / Chat</p><h2 class="emp-title">Team chat</h2></div></header>
<div class="tc-layout<?= $selected?'':' no-room' ?>">
    <aside class="tc-list">
        <div class="tc-list-head"><h3>Chats</h3><button type="button" class="emp-btn emp-btn-dark emp-btn-sm" data-dialog="new-chat-dialog">＋ New chat</button></div>
        <label class="dept-search tc-search"><?= icon('search') ?><span class="sr-only">Search chats</span><input type="search" placeholder="Search people or groups…" data-tc-search></label>
        <div class="tc-tabs" role="tablist"><button type="button" class="active" data-tc-tab="all">All</button><button type="button" data-tc-tab="private">Private</button><button type="button" data-tc-tab="group">Groups</button></div>
        <div class="tc-items chat-list">
            <?php if (!$conversations): ?><p class="muted tc-none">Start a private or group chat to begin.</p><?php endif; ?>
            <?php foreach ($conversations as $conversation): $isGroup=$conversation['conversation_type']==='group'; $label=$isGroup?($conversation['title']?:$conversation['other_members']):$conversation['other_members']; ?>
            <a class="tc-item chat-list-item<?= (int)$conversation['id']===$selectedId?' active':'' ?>" data-chat-id="<?= (int)$conversation['id'] ?>" data-type="<?= h($conversation['conversation_type']) ?>" data-name="<?= h(mb_strtolower(($label?:'').' '.$conversation['other_members'])) ?>" href="chat.php?id=<?= (int)$conversation['id'] ?>">
                <span class="tc-avatar<?= $isGroup?' is-group':'' ?>"><?= $isGroup?icon('people'):h($initials($label)) ?></span>
                <span class="tc-item-text"><strong><?= h($label?:'Chat') ?></strong><small><?= $conversation['last_body']!==null&&$conversation['last_body']!==''?h(mb_strimwidth($conversation['last_body'],0,48,'…')):($conversation['last_message_id']?'📎 File':($isGroup?'Group chat':'Private chat')) ?></small></span>
                <time><?= h($shortDate($conversation['last_at'])) ?></time>
            </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="tc-room" <?= $selected?'data-chat-room data-conversation="'.(int)$selectedId.'"':'' ?>>
        <?php if (!$selected): ?>
            <div class="tc-empty"><span class="tc-empty-icon"><?= icon('chat') ?></span><h3>Your conversation appears here</h3><p class="muted">Choose a chat on the left or start a new one.</p><button type="button" class="emp-btn emp-btn-gold" data-dialog="new-chat-dialog">＋ New chat</button></div>
        <?php else: ?>
            <header class="tc-room-head">
                <a class="tc-back" href="chat.php" aria-label="Back to chats">‹</a>
                <span class="tc-avatar tc-avatar-lg<?= $selected['conversation_type']==='group'?' is-group':'' ?>"><?= $selected['conversation_type']==='group'?icon('people'):h($initials($roomTitle)) ?></span>
                <div class="tc-room-title"><strong><?= h($roomTitle?:'Chat') ?></strong><small><?= h($roomSub) ?></small></div>
                <label class="tc-msg-search" data-tc-msg-search-wrap hidden><span class="sr-only">Search messages</span><input type="search" placeholder="Search in chat…" data-tc-msg-search></label>
                <button type="button" class="aw-icon-btn" data-tc-msg-search-toggle title="Search messages" aria-label="Search messages"><?= icon('search') ?></button>
                <button type="button" class="aw-icon-btn tc-info-toggle" data-tc-info-toggle title="Chat details" aria-label="Chat details"><?= icon('more') ?></button>
            </header>
            <div class="tc-messages chat-messages" id="chat-messages" aria-live="polite">
                <?php $lastDay=''; foreach ($messages as $message): $own=(int)$message['sender_id']===(int)$user['id']; $day=date('Y-m-d',strtotime($message['created_at'])); if ($day!==$lastDay): $lastDay=$day; ?><div class="tc-day" data-day="<?= h($day) ?>"><span><?= h($dayLabel($message['created_at'])) ?></span></div><?php endif; ?>
                <article class="tc-msg chat-message<?= $own?' own':'' ?>" data-message-id="<?= (int)$message['id'] ?>">
                    <?php if (!$own): ?><span class="tc-avatar tc-avatar-xs" title="<?= h($message['sender_name']) ?>"><?= h($initials($message['sender_name'])) ?></span><?php endif; ?>
                    <div class="tc-msg-body">
                        <?php if (!$own && $selected['conversation_type']==='group'): ?><small class="tc-sender"><?= h($message['sender_name']) ?></small><?php endif; ?>
                        <?php if ($message['body']!==''): ?><p class="tc-bubble"><?= h($message['body']) ?></p><?php endif; ?>
                        <?php foreach ($filesByMessage[(int)$message['id']]??[] as $attachment): ?><a class="tc-file chat-attachment" href="download-chat-attachment.php?id=<?= (int)$attachment['id'] ?>"><?= icon('download') ?><span><?= h($attachment['original_file_name']) ?></span><small><?= h(chat_file_size_label((int)$attachment['file_size_bytes'])) ?></small></a><?php endforeach; ?>
                        <time><?= h(date('g:i A',strtotime($message['created_at']))) ?></time>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?><p class="tc-first muted">No messages yet — say hello 👋</p><?php endif; ?>
                <span id="chat-latest"></span>
            </div>
            <form method="post" enctype="multipart/form-data" class="tc-compose chat-compose" data-chat-compose><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>">
                <div class="tc-compose-row">
                    <label class="tc-attach" for="chat-files" title="Attach files" aria-label="Attach files">📎</label><input id="chat-files" name="files[]" type="file" multiple hidden>
                    <label class="sr-only" for="chat-body">Message</label><textarea id="chat-body" name="body" rows="1" maxlength="5000" placeholder="Write a message…"></textarea>
                    <button class="tc-send" type="submit" aria-label="Send">➤</button>
                </div>
                <div class="tc-compose-foot"><small id="chat-file-names"></small><progress id="chat-upload-progress" max="100" value="0" hidden></progress><small id="chat-upload-status" role="status"></small><small class="tc-hint">Enter to send · Shift+Enter for a new line · Up to 5 GB per message</small></div>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($selected): ?>
    <aside class="tc-info" data-tc-info>
        <button type="button" class="tc-info-close" data-tc-info-toggle aria-label="Close details">×</button>
        <?php if ($roomProfile): ?>
            <span class="tc-avatar tc-avatar-xl"><?= h($initials($roomProfile['full_name'])) ?></span>
            <h3 class="tc-info-name"><?= h($roomProfile['full_name']) ?></h3>
            <p class="tc-info-sub"><?= h(implode(' · ',array_filter([$roomProfile['designation']?:ucfirst($roomProfile['role']),$roomProfile['department_name'],'@'.$roomProfile['username']]))) ?></p>
            <?php if ($isStaff && $roomProfile['role']==='employee'): ?>
                <p class="tc-info-label">Quick actions</p>
                <a class="tc-action" href="manager-assign-work.php">＋ Assign a task</a>
                <a class="tc-action" href="manager-assigned-status.php?employee=<?= (int)$roomProfile['id'] ?>">View their tasks</a>
            <?php endif; ?>
        <?php else: ?>
            <span class="tc-avatar tc-avatar-xl is-group"><?= icon('people') ?></span>
            <h3 class="tc-info-name"><?= h($roomTitle) ?></h3>
            <p class="tc-info-sub">Group · <?= count($members)+1 ?> members</p>
            <p class="tc-info-label">Members</p>
            <div class="tc-members"><span class="tc-member"><span class="tc-avatar tc-avatar-xs"><?= h($initials($user['full_name'])) ?></span><?= h($user['full_name']) ?> <small>(you)</small></span><?php foreach ($members as $member): ?><span class="tc-member"><span class="tc-avatar tc-avatar-xs"><?= h($initials($member['full_name'])) ?></span><?= h($member['full_name']) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
        <p class="tc-info-label">Shared files</p>
        <?php if (!$sharedFiles): ?><div class="tc-nofiles">No files shared yet</div><?php endif; ?>
        <div class="tc-shared"><?php foreach ($sharedFiles as $file): ?><a href="download-chat-attachment.php?id=<?= (int)$file['id'] ?>"><?= icon('document') ?><span><?= h($file['original_file_name']) ?></span><small><?= h(chat_file_size_label((int)$file['file_size_bytes'])) ?></small></a><?php endforeach; ?></div>
    </aside>
    <?php endif; ?>
</div>
</div>

<dialog id="new-chat-dialog" class="detail-dialog tc-dialog">
    <div class="dialog-heading"><h2>New chat</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
    <form method="post" class="tc-new-form chat-start-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_conversation">
        <div class="cl-segment tc-type"><label><input type="radio" name="conversation_type" value="private" checked><span>Private chat</span></label><label><input type="radio" name="conversation_type" value="group"><span>Group chat</span></label></div>
        <label id="chat-title-field" hidden>Group name (optional)<input id="chat-title" name="title" maxlength="120" placeholder="e.g. Design team"></label>
        <label class="dept-search"><?= icon('search') ?><span class="sr-only">Search people</span><input type="search" placeholder="Search people…" data-tc-people-search></label>
        <p class="help" id="chat-people-help">Private chat: choose one person.</p>
        <div class="tc-people" data-tc-people>
            <?php foreach ($people as $person): ?><label class="tc-person" data-name="<?= h(mb_strtolower($person['full_name'].' '.$person['department_name'])) ?>"><input type="radio" name="user_ids[]" value="<?= (int)$person['id'] ?>" data-tc-person><span class="tc-avatar tc-avatar-sm"><?= h($initials($person['full_name'])) ?></span><span><strong><?= h($person['full_name']) ?></strong><small><?= h(ucfirst($person['role'])) ?><?= $person['department_name']?' · '.h($person['department_name']):'' ?></small></span></label><?php endforeach; ?>
        </div>
        <button class="emp-btn emp-btn-gold" type="submit">Start chat</button>
    </form>
</dialog>
<script>
(() => {
    const me=<?= (int)$user['id'] ?>, isGroupRoom=<?= $selected && $selected['conversation_type']==='group' ? 'true' : 'false' ?>;
    const initials=n=>(n||'').trim().split(/\s+/).slice(0,2).map(p=>p.charAt(0).toUpperCase()).join('')||'?';
    const timeLabel=s=>{const d=new Date(String(s).replace(' ','T'));return isNaN(d)?s:d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});};
    const dayKey=s=>String(s).slice(0,10);
    // New chat dialog: private = one person (radio), group = many (checkbox)
    const dialog=document.getElementById('new-chat-dialog');
    if(dialog){
        const titleField=document.getElementById('chat-title-field'),help=document.getElementById('chat-people-help');
        dialog.querySelectorAll('[name=conversation_type]').forEach(r=>r.addEventListener('change',()=>{const group=dialog.querySelector('[name=conversation_type]:checked').value==='group';titleField.hidden=!group;help.textContent=group?'Select as many people as you need for this group.':'Private chat: choose one person.';dialog.querySelectorAll('[data-tc-person]').forEach(i=>{i.type=group?'checkbox':'radio';i.checked=false;});}));
        dialog.querySelector('[data-tc-people-search]').addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();dialog.querySelectorAll('.tc-person').forEach(p=>{p.hidden=q!==''&&!p.dataset.name.includes(q);});});
        dialog.querySelector('form').addEventListener('submit',e=>{if(!dialog.querySelector('[data-tc-person]:checked')){e.preventDefault();help.textContent='Choose at least one person to start the chat.';}});
    }
    // Chat list: search + tabs
    let tab='all';
    const search=document.querySelector('[data-tc-search]');
    const filterList=()=>{const q=(search?.value||'').trim().toLowerCase();document.querySelectorAll('.tc-item').forEach(i=>{i.hidden=(tab!=='all'&&i.dataset.type!==tab)||(q!==''&&!(i.dataset.name||'').includes(q));});};
    search?.addEventListener('input',filterList);
    document.querySelectorAll('[data-tc-tab]').forEach(b=>b.addEventListener('click',()=>{tab=b.dataset.tcTab;document.querySelectorAll('[data-tc-tab]').forEach(x=>x.classList.toggle('active',x===b));filterList();}));
    async function refreshConversations(){try{const response=await fetch('chat.php?conversations=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();const list=document.querySelector('.tc-items');if(!list)return;payload.conversations.forEach(c=>{if(list.querySelector(`[data-chat-id="${c.id}"]`))return;const group=c.conversation_type==='group',label=group?(c.title||c.other_members||'Group chat'):(c.other_members||'Private chat');const link=document.createElement('a');link.className='tc-item chat-list-item';link.dataset.chatId=c.id;link.dataset.type=c.conversation_type;link.dataset.name=label.toLowerCase();link.href=`chat.php?id=${c.id}`;const av=document.createElement('span');av.className='tc-avatar'+(group?' is-group':'');av.textContent=group?'👥':initials(label);const text=document.createElement('span');text.className='tc-item-text';const name=document.createElement('strong');name.textContent=label;const kind=document.createElement('small');kind.textContent='New '+(group?'group':'private')+' chat';text.append(name,kind);link.append(av,text);list.prepend(link);list.querySelector('.tc-none')?.remove();});filterList();}catch(_){}}
    window.setInterval(refreshConversations,5000);
    // Details panel toggle (mobile/tablet) + message search
    document.querySelectorAll('[data-tc-info-toggle]').forEach(b=>b.addEventListener('click',()=>document.querySelector('.tc-layout')?.classList.toggle('show-info')));
    const msgSearchWrap=document.querySelector('[data-tc-msg-search-wrap]'),msgSearch=document.querySelector('[data-tc-msg-search]');
    document.querySelector('[data-tc-msg-search-toggle]')?.addEventListener('click',()=>{msgSearchWrap.hidden=!msgSearchWrap.hidden;if(!msgSearchWrap.hidden)msgSearch.focus();else{msgSearch.value='';msgSearch.dispatchEvent(new Event('input'));}});
    msgSearch?.addEventListener('input',()=>{const q=msgSearch.value.trim().toLowerCase();document.querySelectorAll('.tc-msg').forEach(m=>{m.classList.toggle('is-dim',q!==''&&!m.textContent.toLowerCase().includes(q));});});
    // Live messages
    const room=document.querySelector('[data-chat-room]'), box=document.getElementById('chat-messages');
    if(!room||!box)return;
    let lastId=Math.max(0,...Array.from(box.querySelectorAll('[data-message-id]'),item=>Number(item.dataset.messageId)||0));
    let lastDay=Array.from(box.querySelectorAll('.tc-day')).pop()?.dataset.day||'';
    const append=message=>{
        if(box.querySelector(`[data-message-id="${message.id}"]`))return;
        box.querySelector('.tc-first')?.remove();
        const day=dayKey(message.created_at);
        if(day!==lastDay){lastDay=day;const sep=document.createElement('div');sep.className='tc-day';sep.dataset.day=day;const s=document.createElement('span');s.textContent=day===new Date().toISOString().slice(0,10)?'Today':day;sep.append(s);box.insertBefore(sep,document.getElementById('chat-latest'));}
        const own=Number(message.sender_id)===me;
        const item=document.createElement('article');item.className='tc-msg chat-message'+(own?' own':'');item.dataset.messageId=message.id;
        if(!own){const av=document.createElement('span');av.className='tc-avatar tc-avatar-xs';av.title=message.sender_name;av.textContent=initials(message.sender_name);item.append(av);}
        const body=document.createElement('div');body.className='tc-msg-body';
        if(!own&&isGroupRoom){const s=document.createElement('small');s.className='tc-sender';s.textContent=message.sender_name;body.append(s);}
        if(message.body){const p=document.createElement('p');p.className='tc-bubble';p.textContent=message.body;body.append(p);}
        (message.files||[]).forEach(file=>{const link=document.createElement('a'),name=document.createElement('span'),size=document.createElement('small');link.className='tc-file chat-attachment';link.href=`download-chat-attachment.php?id=${file.id}`;link.textContent='⬇ ';name.textContent=file.original_file_name;size.textContent=file.file_size_bytes>=1073741824?`${(file.file_size_bytes/1073741824).toFixed(2)} GB`:`${(file.file_size_bytes/1048576).toFixed(1)} MB`;link.append(name,size);body.append(link);});
        const t=document.createElement('time');t.textContent=timeLabel(message.created_at);body.append(t);
        item.append(body);box.insertBefore(item,document.getElementById('chat-latest'));
        lastId=Math.max(lastId,Number(message.id)||0);box.scrollTop=box.scrollHeight;
    };
    async function refresh(){try{const response=await fetch(`chat.php?messages=1&id=${room.dataset.conversation}&after=${lastId}`,{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const payload=await response.json();payload.messages.forEach(append);}catch(_){}}
    box.scrollTop=box.scrollHeight; window.setInterval(refresh,3000);
    // Composer: auto-grow, Enter to send, file names, upload progress
    const compose=document.querySelector('[data-chat-compose]'),textarea=document.getElementById('chat-body'),files=document.getElementById('chat-files'),fileNames=document.getElementById('chat-file-names');
    textarea?.addEventListener('input',()=>{textarea.style.height='auto';textarea.style.height=Math.min(textarea.scrollHeight,140)+'px';});
    textarea?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();compose.requestSubmit();}});
    files?.addEventListener('change',()=>{fileNames.textContent=files.files.length?'📎 '+Array.from(files.files,f=>f.name).join(', '):'';});
    if(compose)compose.addEventListener('submit',event=>{
        event.preventDefault();
        if(!textarea.value.trim()&&!files.files.length)return;
        const xhr=new XMLHttpRequest(),progress=document.getElementById('chat-upload-progress'),status=document.getElementById('chat-upload-status'),button=compose.querySelector('button[type="submit"]');
        xhr.open('POST',compose.action||window.location.href);button.disabled=true;
        if(files.files.length){progress.hidden=false;status.textContent='Uploading…';}
        xhr.upload.onprogress=e=>{if(e.lengthComputable&&files.files.length){progress.value=Math.round(e.loaded/e.total*100);status.textContent=`Uploading… ${progress.value}%`;}};
        xhr.onload=()=>{const responseDoc=new DOMParser().parseFromString(xhr.responseText,'text/html'),serverError=responseDoc.querySelector('.alert.error');if(serverError){button.disabled=false;progress.hidden=true;status.textContent=serverError.textContent.trim();return;}if(xhr.status>=200&&xhr.status<400){textarea.value='';textarea.style.height='auto';files.value='';fileNames.textContent='';status.textContent='';progress.hidden=true;button.disabled=false;refresh();textarea.focus();}else{button.disabled=false;progress.hidden=true;status.textContent=xhr.status===413?'Upload exceeds the server request size limit.':'Message or file could not be sent. Please try again.';}};
        xhr.onerror=()=>{button.disabled=false;progress.hidden=true;status.textContent='Upload failed. Check the connection and try again.';};xhr.send(new FormData(compose));
    });
})();
</script>
<?php page_end(); ?>
