<?php
require_once __DIR__.'/includes/layout.php';
$user=require_roles(['admin','manager','employee']);
const CHAT_ONLINE_SECONDS=90;

// Keep presence fresh while the chat page (and its 3-second polling) is open.
query('UPDATE users SET last_seen_at=NOW() WHERE id=? AND (last_seen_at IS NULL OR last_seen_at<NOW()-INTERVAL 15 SECOND)',[$user['id']]);

function chat_file_size_label(int $bytes): string
{
    if ($bytes>=1073741824) return number_format($bytes/1073741824,2).' GB';
    if ($bytes>=1048576) return number_format($bytes/1048576,1).' MB';
    if ($bytes>=1024) return number_format($bytes/1024,0).' KB';
    return $bytes.' B';
}

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

function chat_short_date(?string $at): string
{
    if (!$at) return '';
    $time=strtotime($at); $day=date('Y-m-d',$time);
    if ($day===today()) return date('g:i A',$time);
    if ($day===date('Y-m-d',strtotime('-1 day'))) return 'Yesterday';
    return date('j M',$time);
}

function chat_mark_read(int $conversationId,int $userId): void
{
    query('UPDATE chat_participants p SET p.last_read_message_id=GREATEST(p.last_read_message_id,COALESCE((SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=?),0)) WHERE p.conversation_id=? AND p.user_id=?',[$conversationId,$conversationId,$userId]);
}

/** Read receipt + presence for the open conversation. */
function chat_room_state(array $conversation,int $userId): array
{
    $others=rows('SELECT p.last_read_message_id,u.last_seen_at,(u.last_seen_at IS NOT NULL AND u.last_seen_at>=NOW()-INTERVAL '.CHAT_ONLINE_SECONDS.' SECOND) AS is_online,TIMESTAMPDIFF(SECOND,u.last_seen_at,NOW()) AS seconds_ago FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id<>?',[(int)$conversation['id'],$userId]);
    $readUpto=$others?min(array_map(static fn($o)=>(int)$o['last_read_message_id'],$others)):0;
    if ($conversation['conversation_type']==='group') {
        $online=count(array_filter($others,static fn($o)=>(int)$o['is_online']===1));
        return ['read_upto'=>$readUpto,'online'=>$online>0,'presence'=>$online?$online.' online':(count($others)+1).' members'];
    }
    $other=$others[0]??null;
    if (!$other || !$other['last_seen_at']) return ['read_upto'=>$readUpto,'online'=>false,'presence'=>'Offline'];
    if ((int)$other['is_online']===1) return ['read_upto'=>$readUpto,'online'=>true,'presence'=>'Online'];
    $ago=max(0,(int)$other['seconds_ago']); $seen=strtotime($other['last_seen_at']);
    if ($ago<3600) $label='Last seen '.max(1,intdiv($ago,60)).' min ago';
    elseif (date('Y-m-d',$seen)===today()) $label='Last seen today at '.date('g:i A',$seen);
    elseif (date('Y-m-d',$seen)===date('Y-m-d',strtotime('-1 day'))) $label='Last seen yesterday at '.date('g:i A',$seen);
    else $label='Last seen '.date('j M',$seen);
    return ['read_upto'=>$readUpto,'online'=>false,'presence'=>$label];
}

/** Conversation list rows with label, preview, time, unread count and online flag. */
function chat_conversation_rows(int $userId): array
{
    $list=rows("SELECT c.id,c.conversation_type,c.title,c.created_at,
        (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=c.id) AS last_message_id,
        (SELECT m.body FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
        (SELECT m.deleted_at IS NOT NULL FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_deleted,
        (SELECT m.sender_id FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
        (SELECT m.created_at FROM chat_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
        (SELECT GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_members,
        (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id=c.id AND m.id>mine.last_read_message_id AND m.sender_id<>?) AS unread,
        (SELECT MAX(u.last_seen_at IS NOT NULL AND u.last_seen_at>=NOW()-INTERVAL ".CHAT_ONLINE_SECONDS." SECOND) FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=c.id AND p.user_id<>?) AS other_online
        FROM chat_conversations c JOIN chat_participants mine ON mine.conversation_id=c.id AND mine.user_id=?
        ORDER BY COALESCE((SELECT MAX(m.created_at) FROM chat_messages m WHERE m.conversation_id=c.id),c.created_at) DESC,c.id DESC",[$userId,$userId,$userId,$userId]);
    foreach ($list as &$row) {
        $isGroup=$row['conversation_type']==='group';
        $row['label']=($isGroup?($row['title']?:$row['other_members']):$row['other_members'])?:'Chat';
        if ((int)$row['last_deleted']===1) $row['preview']=((int)$row['last_sender']===$userId?'You: ':'').'🚫 Message deleted';
        elseif ($row['last_body']!==null && $row['last_body']!=='') $row['preview']=((int)$row['last_sender']===$userId?'You: ':'').mb_strimwidth($row['last_body'],0,60,'…');
        elseif ($row['last_message_id']) $row['preview']=((int)$row['last_sender']===$userId?'You: ':'').'📎 File';
        else $row['preview']=$isGroup?'Group chat':'Private chat';
        $row['when']=chat_short_date($row['last_at']);
        $row['unread']=(int)$row['unread'];
        $row['online']=!$isGroup && (int)$row['other_online']===1;
        unset($row['last_body'],$row['last_deleted'],$row['other_online']);
    }
    unset($row);
    return $list;
}

function chat_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}

if (isset($_GET['messages'])) {
    $conversationId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $afterId=filter_var($_GET['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if (!$conversationId || $afterId===false) fail(400,'Invalid chat request.');
    $conversation=one('SELECT c.id,c.conversation_type FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=?',[$conversationId,$user['id']]);
    if (!$conversation) fail(403,'You are not a member of this chat.');
    if (($_GET['seen']??'')==='1') chat_mark_read($conversationId,(int)$user['id']);
    $messages=rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at,m.deleted_at IS NOT NULL AS is_deleted FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id LIMIT 100',[$conversationId,$afterId]);
    $files=chat_attachments_from_rows($messages);
    foreach ($messages as &$message) {
        $message['files']=array_map(static fn($f)=>$f+['size_label'=>chat_file_size_label((int)$f['file_size_bytes'])],$files[(int)$message['id']]??[]);
        $message['time']=date('g:i A',strtotime($message['created_at']));
        $message['day']=date('Y-m-d',strtotime($message['created_at']));
    }
    unset($message);
    $deletedIds=array_map('intval',array_column(rows('SELECT id FROM chat_messages WHERE conversation_id=? AND deleted_at IS NOT NULL AND deleted_at>=NOW()-INTERVAL 1 DAY',[$conversationId]),'id'));
    chat_json(['messages'=>$messages,'deleted'=>$deletedIds]+chat_room_state($conversation,(int)$user['id']));
}
if (isset($_GET['conversations'])) {
    chat_json(['conversations'=>chat_conversation_rows((int)$user['id'])]);
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
            if ($type==='private') {
                // Reuse an existing private chat with the same person instead of creating duplicates.
                $existing=one("SELECT c.id FROM chat_conversations c JOIN chat_participants a ON a.conversation_id=c.id AND a.user_id=? JOIN chat_participants b ON b.conversation_id=c.id AND b.user_id=? WHERE c.conversation_type='private' AND (SELECT COUNT(*) FROM chat_participants x WHERE x.conversation_id=c.id)=2 ORDER BY c.id LIMIT 1",[$user['id'],$participantIds[0]]);
                if ($existing) redirect('chat.php?id='.(int)$existing['id']);
            }
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
        if ($action==='remove_member') {
            // Admins, managers and the person who created the group can remove a member.
            $conversationId=positive_id($_POST['conversation_id']??null);
            $removeId=positive_id($_POST['user_id']??null);
            $group=one("SELECT c.id,c.created_by FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.id=? AND c.conversation_type='group'",[$user['id'],$conversationId]);
            if (!$group) throw new InvalidArgumentException('You can only manage a group you are in.');
            if (!in_array($user['role'],['admin','manager'],true) && (int)$group['created_by']!==(int)$user['id']) throw new InvalidArgumentException('Only the group creator, the Managing Director and managers can remove members.');
            if ($removeId===(int)$user['id']) throw new InvalidArgumentException('You cannot remove yourself.');
            $removed=one('SELECT u.full_name FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id=?',[$conversationId,$removeId]);
            if (!$removed) throw new InvalidArgumentException('This person is no longer in the group.');
            db()->beginTransaction();
            query('DELETE FROM chat_participants WHERE conversation_id=? AND user_id=?',[$conversationId,$removeId]);
            query('INSERT INTO chat_messages (conversation_id,sender_id,body) VALUES (?,?,?)',[$conversationId,$user['id'],'➖ Removed '.$removed['full_name'].' from the group']);
            $messageId=(int)db()->lastInsertId();
            query('UPDATE chat_participants SET last_read_message_id=GREATEST(last_read_message_id,?) WHERE conversation_id=? AND user_id=?',[$messageId,$conversationId,$user['id']]);
            query('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$conversationId]);
            db()->commit();
            redirect('chat.php?id='.$conversationId.'#chat-latest');
        }
        if ($action==='add_members') {
            // Any member of a group can add more people to it.
            $conversationId=positive_id($_POST['conversation_id']??null);
            if (!one("SELECT c.id FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.id=? AND c.conversation_type='group'",[$user['id'],$conversationId])) throw new InvalidArgumentException('You can only add people to a group you are in.');
            $rawIds=$_POST['user_ids']??[];
            if (!is_array($rawIds) || !$rawIds) throw new InvalidArgumentException('Choose at least one person to add.');
            $newIds=array_values(array_unique(array_map(static fn($v)=>positive_id($v),$rawIds)));
            $placeholders=implode(',',array_fill(0,count($newIds),'?'));
            $valid=rows("SELECT id,full_name FROM users WHERE id IN ($placeholders) AND role IN ('admin','manager','employee') AND account_status='active' AND deleted_at IS NULL AND id NOT IN (SELECT user_id FROM chat_participants WHERE conversation_id=?) ORDER BY full_name",array_merge($newIds,[$conversationId]));
            if (!$valid) throw new InvalidArgumentException('Those people are already in this group.');
            db()->beginTransaction();
            // New members start "read" up to now, so old messages do not show as unread.
            $lastMessage=(int)(one('SELECT MAX(id) AS m FROM chat_messages WHERE conversation_id=?',[$conversationId])['m']??0);
            foreach ($valid as $person) query('INSERT INTO chat_participants (conversation_id,user_id,added_by,last_read_message_id) VALUES (?,?,?,?)',[$conversationId,$person['id'],$user['id'],$lastMessage]);
            query('INSERT INTO chat_messages (conversation_id,sender_id,body) VALUES (?,?,?)',[$conversationId,$user['id'],'➕ Added '.implode(', ',array_column($valid,'full_name')).' to the group']);
            $messageId=(int)db()->lastInsertId();
            query('UPDATE chat_participants SET last_read_message_id=GREATEST(last_read_message_id,?) WHERE conversation_id=? AND user_id=?',[$messageId,$conversationId,$user['id']]);
            query('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$conversationId]);
            db()->commit();
            redirect('chat.php?id='.$conversationId.'#chat-latest');
        }
        if ($action==='delete_message') {
            // Only the sender can delete their own message; it stays as a "deleted" placeholder and its files are removed.
            $messageId=positive_id($_POST['message_id']??null);
            $message=one('SELECT m.id,m.conversation_id FROM chat_messages m JOIN chat_participants p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE m.id=? AND m.sender_id=? AND m.deleted_at IS NULL',[$user['id'],$messageId,$user['id']]);
            if (!$message) throw new InvalidArgumentException('You can delete only your own messages.');
            $paths=array_column(rows('SELECT storage_path FROM chat_attachments WHERE message_id=?',[$messageId]),'storage_path');
            db()->beginTransaction();
            query('DELETE FROM chat_attachments WHERE message_id=?',[$messageId]);
            query("UPDATE chat_messages SET body='',deleted_at=NOW() WHERE id=?",[$messageId]);
            db()->commit();
            foreach ($paths as $path) { $absolute=__DIR__.'/'.$path; if (str_starts_with($path,'storage/chat/') && is_file($absolute)) @unlink($absolute); }
            if (($_POST['ajax']??'')==='1') chat_json(['ok'=>true]);
            redirect('chat.php?id='.(int)$message['conversation_id'].'#chat-latest');
        }
        if ($action==='send_message') {
            $conversationId=positive_id($_POST['conversation_id']??null);
            if (!one('SELECT conversation_id FROM chat_participants WHERE conversation_id=? AND user_id=?',[$conversationId,$user['id']])) throw new InvalidArgumentException('You are not a member of this chat.');
            $body=trim(text_input('body',5000,false));
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
            // Must match download-chat-attachment.php, which serves files from <project>/storage/chat.
            $directory=__DIR__.'/storage/chat';
            if ($files && !is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new InvalidArgumentException('Unable to create secure chat file storage.');
            if ($files && !is_writable($directory)) throw new InvalidArgumentException('Secure chat file storage is not writable by PHP.');
            db()->beginTransaction();
            query('INSERT INTO chat_messages (conversation_id,sender_id,body) VALUES (?,?,?)',[$conversationId,$user['id'],$body]);
            $messageId=(int)db()->lastInsertId();
            foreach ($files as $file) {
                $relative='storage/chat/'.bin2hex(random_bytes(24)).'.bin';
                $absolute=__DIR__.'/'.$relative;
                if (!move_uploaded_file($file['tmp'],$absolute)) throw new InvalidArgumentException('A file could not be stored. Check available disk space and chat storage permissions.');
                $storedFiles[]=$absolute;
                query('INSERT INTO chat_attachments (message_id,original_file_name,storage_path,mime_type,file_size_bytes,uploaded_by) VALUES (?,?,?,?,?,?)',[$messageId,$file['name'],$relative,$file['mime'],$file['size'],$user['id']]);
            }
            query('UPDATE chat_participants SET last_read_message_id=GREATEST(last_read_message_id,?) WHERE conversation_id=? AND user_id=?',[$messageId,$conversationId,$user['id']]);
            query('UPDATE chat_conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?',[$conversationId]);
            db()->commit();
            redirect('chat.php?id='.$conversationId.'#chat-latest');
        }
        throw new InvalidArgumentException('Invalid chat action.');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        foreach ($storedFiles as $path) if (is_file($path)) @unlink($path);
        $error=mutation_error($exception);
        if (($_POST['ajax']??'')==='1') { http_response_code(400); chat_json(['ok'=>false,'error'=>$error]); }
    }
}

$selectedId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$selected=$selectedId?one('SELECT c.* FROM chat_conversations c JOIN chat_participants p ON p.conversation_id=c.id WHERE c.id=? AND p.user_id=?',[$selectedId,$user['id']]):null;
if ($selectedId && !$selected) fail(404,'Chat not found.');
if ($selected) chat_mark_read($selectedId,(int)$user['id']);
$conversations=chat_conversation_rows((int)$user['id']);
if (!$selected && $conversations) {
    $selectedId=(int)$conversations[0]['id'];
    $selected=one('SELECT * FROM chat_conversations WHERE id=?',[$selectedId]);
    chat_mark_read($selectedId,(int)$user['id']);
    $conversations[0]['unread']=0;
}
$messages=$selected?array_reverse(rows('SELECT m.id,m.sender_id,u.full_name AS sender_name,m.body,m.created_at,m.deleted_at IS NOT NULL AS is_deleted FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 100',[$selectedId])):[];
$filesByMessage=chat_attachments_from_rows($messages);
$people=rows("SELECT u.id,u.full_name,u.role,d.name AS department_name FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.id<>? AND u.role IN ('admin','manager','employee') AND u.account_status='active' AND u.deleted_at IS NULL ORDER BY u.full_name",[$user['id']]);
$initials=static function(?string $name): string { $o=''; foreach (array_slice(preg_split('/\s+/',trim((string)$name))?:[],0,2) as $p) $o.=mb_strtoupper(mb_substr($p,0,1)); return $o?:'?'; };
$profileOf=static fn(int $id)=>one("SELECT u.id,u.full_name,u.username,u.role,u.deleted_at,ep.designation,d.name AS department_name FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN departments d ON d.id=ep.department_id WHERE u.id=?",[$id]);
$dayLabel=static function(string $at): string { $d=date('Y-m-d',strtotime($at)); return $d===today()?'Today':($d===date('Y-m-d',strtotime('-1 day'))?'Yesterday':date('l, j M Y',strtotime($at))); };
$isStaff=in_array($user['role'],['admin','manager'],true);
$roomTitle=''; $roomProfile=null; $members=[]; $sharedFiles=[]; $roomState=['read_upto'=>0,'online'=>false,'presence'=>''];
if ($selected) {
    $members=rows("SELECT u.id,u.full_name,u.role FROM chat_participants p JOIN users u ON u.id=p.user_id WHERE p.conversation_id=? AND p.user_id<>? ORDER BY u.full_name",[$selectedId,$user['id']]);
    $memberNames=implode(', ',array_column($members,'full_name'));
    $roomTitle=$selected['conversation_type']==='group'?($selected['title']?:$memberNames):$memberNames;
    if ($selected['conversation_type']==='private' && $members) $roomProfile=$profileOf((int)$members[0]['id']);
    $sharedFiles=rows('SELECT a.id,a.original_file_name,a.file_size_bytes,a.created_at FROM chat_attachments a JOIN chat_messages m ON m.id=a.message_id WHERE m.conversation_id=? ORDER BY a.id DESC LIMIT 12',[$selectedId]);
    $roomState=chat_room_state($selected,(int)$user['id']);
}
// Manual WhatsApp share: private chats open straight to the other person's number (adds 91 to 10-digit numbers).
$waPhone='';
if ($roomProfile) {
    $phoneDigits=preg_replace('/\D+/','',(string)(one('SELECT phone FROM employee_profiles WHERE user_id=?',[(int)$roomProfile['id']])['phone']??''));
    if (strlen($phoneDigits)===10) $phoneDigits='91'.$phoneDigits;
    if (strlen($phoneDigits)>=11) $waPhone=$phoneDigits;
}
$waBase='https://wa.me/'.$waPhone;
$roomSub=$roomProfile?array_filter([$roomProfile['department_name'],$roomProfile['designation']?:role_label($roomProfile['role'])]):($selected?[count($members)+1 .' members']:[]);
if ($selected && $selected['conversation_type']==='group' && !$roomState['online']) $roomSub=[]; // presence already shows the member count
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
            <?php foreach ($conversations as $conversation): $isGroup=$conversation['conversation_type']==='group'; $active=(int)$conversation['id']===$selectedId; ?>
            <a class="tc-item chat-list-item<?= $active?' active':'' ?><?= !$active&&$conversation['unread']?' has-unread':'' ?>" data-chat-id="<?= (int)$conversation['id'] ?>" data-type="<?= h($conversation['conversation_type']) ?>" data-name="<?= h(mb_strtolower($conversation['label'].' '.$conversation['other_members'])) ?>" href="chat.php?id=<?= (int)$conversation['id'] ?>">
                <span class="tc-avatar<?= $isGroup?' is-group':'' ?>"><?= $isGroup?icon('people'):h($initials($conversation['label'])) ?><i class="tc-dot" data-tc-dot<?= $conversation['online']?'':' hidden' ?>></i></span>
                <span class="tc-item-text"><strong><?= h($conversation['label']) ?></strong><small data-tc-preview><?= h($conversation['preview']) ?></small></span>
                <span class="tc-item-meta"><time data-tc-when><?= h($conversation['when']) ?></time><b class="tc-unread" data-tc-unread<?= !$active&&$conversation['unread']?'':' hidden' ?>><?= $conversation['unread']>99?'99+':(int)$conversation['unread'] ?></b></span>
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
                <div class="tc-room-title"><strong><?= h($roomTitle?:'Chat') ?></strong><small><span class="tc-presence<?= $roomState['online']?' is-online':'' ?>" data-tc-presence><?= h($roomState['presence']) ?></span><?php foreach ($roomSub as $part): ?> · <?= h($part) ?><?php endforeach; ?></small></div>
                <label class="tc-msg-search" data-tc-msg-search-wrap hidden><span class="sr-only">Search messages</span><input type="search" placeholder="Search in chat…" data-tc-msg-search></label>
                <button type="button" class="tc-head-btn" data-tc-msg-search-toggle title="Search messages" aria-label="Search messages"><?= icon('search') ?></button>
                <button type="button" class="tc-head-btn" data-tc-info-toggle title="Chat details" aria-label="Chat details"><?= icon('kebab') ?></button>
            </header>
            <div class="tc-messages chat-messages" id="chat-messages" aria-live="polite">
                <?php $lastDay=''; foreach ($messages as $message): $own=(int)$message['sender_id']===(int)$user['id']; $day=date('Y-m-d',strtotime($message['created_at'])); if ($day!==$lastDay): $lastDay=$day; ?><div class="tc-day" data-day="<?= h($day) ?>"><span><?= h($dayLabel($message['created_at'])) ?></span></div><?php endif; ?>
                <article class="tc-msg chat-message<?= $own?' own':'' ?>" data-message-id="<?= (int)$message['id'] ?>"<?= (int)$message['is_deleted']===1?' data-deleted="1"':'' ?>>
                    <?php if (!$own): ?><span class="tc-avatar tc-avatar-xs" title="<?= h($message['sender_name']) ?>"><?= h($initials($message['sender_name'])) ?></span><?php endif; ?>
                    <div class="tc-msg-body">
                        <?php if (!$own && $selected['conversation_type']==='group'): ?><small class="tc-sender"><?= h($message['sender_name']) ?></small><?php endif; ?>
                        <?php if ((int)$message['is_deleted']===1): ?><p class="tc-bubble tc-deleted">🚫 This message was deleted</p><?php elseif ($message['body']!==''): ?><p class="tc-bubble"><?= h($message['body']) ?></p><?php endif; ?>
                        <?php foreach ($filesByMessage[(int)$message['id']]??[] as $attachment): ?><a class="tc-file chat-attachment" href="download-chat-attachment.php?id=<?= (int)$attachment['id'] ?>"><?= icon('download') ?><span><?= h($attachment['original_file_name']) ?></span><small><?= h(chat_file_size_label((int)$attachment['file_size_bytes'])) ?></small></a><?php endforeach; ?>
                        <small class="tc-meta"><time datetime="<?= h($message['created_at']) ?>"><?= h(date('g:i A',strtotime($message['created_at']))) ?></time><?php if ($own): $seen=(int)$message['id']<=$roomState['read_upto']; ?> · <span class="tc-status<?= $seen?' is-seen':'' ?>" data-tc-status><?= $seen?'Seen':'Sent' ?></span><?php endif; ?><?php $waFilesJson=json_encode(array_map(static fn($f)=>['id'=>(int)$f['id'],'name'=>$f['original_file_name'],'mime'=>$f['mime_type']],$filesByMessage[(int)$message['id']]??[]),JSON_UNESCAPED_UNICODE); if ((int)$message['is_deleted']!==1 && ($message['body']!=='' || $waFilesJson!=='[]')): ?> · <a class="tc-wa" href="<?= h($waBase.'?text='.rawurlencode($message['body'])) ?>" target="bhavi_whatsapp" data-mode="web" data-text="<?= h($message['body']) ?>" data-files="<?= h($waFilesJson) ?>" title="Open in WhatsApp Web (Chrome)">WhatsApp Web</a><span class="tc-wa-app"> · <a class="tc-wa" href="whatsapp://send" data-mode="app" data-text="<?= h($message['body']) ?>" data-files="<?= h($waFilesJson) ?>" title="Open in WhatsApp app">App</a></span><?php endif; ?><?php if ($own && (int)$message['is_deleted']!==1): ?> · <button type="button" class="tc-del" data-tc-delete title="Delete this message">Delete</button><?php endif; ?></small>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php if (!$messages): ?><p class="tc-first muted">No messages yet — say hello 👋</p><?php endif; ?>
                <span id="chat-latest"></span>
            </div>
            <form method="post" enctype="multipart/form-data" class="tc-compose" data-chat-compose><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>">
                <div class="tc-compose-row">
                    <label class="tc-attach" for="chat-files" title="Attach files" aria-label="Attach files"><?= icon('paperclip') ?></label><input id="chat-files" name="files[]" type="file" multiple hidden>
                    <label class="sr-only" for="chat-body">Message</label><textarea id="chat-body" name="body" rows="1" maxlength="5000" placeholder="Write a message…"></textarea>
                    <button class="tc-send" type="submit" aria-label="Send"><?= icon('send') ?></button>
                </div>
                <div class="tc-compose-foot"><small class="tc-hint">Enter to send · Up to 5 GB per message</small><small id="chat-file-names"></small><progress id="chat-upload-progress" max="100" value="0" hidden></progress><small id="chat-upload-status" role="status"></small></div>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($selected): ?>
    <aside class="tc-info" data-tc-info>
        <button type="button" class="tc-info-close" data-tc-info-toggle aria-label="Close details">×</button>
        <?php if ($roomProfile): ?>
            <span class="tc-avatar tc-avatar-xl"><?= h($initials($roomProfile['full_name'])) ?></span>
            <h3 class="tc-info-name"><?= h($roomProfile['full_name']) ?></h3>
            <p class="tc-info-sub"><?= h(implode(' · ',array_filter([$roomProfile['designation']?:role_label($roomProfile['role']),$roomProfile['department_name'],($roomProfile['deleted_at'] || str_starts_with((string)$roomProfile['username'],'!deleted'))?'Former employee':'@'.$roomProfile['username']]))) ?></p>
            <?php if ($isStaff && $roomProfile['role']==='employee'): ?>
                <p class="tc-info-label">Quick actions</p>
                <a class="tc-action" href="manager-assign-work.php">+ Assign a task</a>
                <a class="tc-action" href="manager-assigned-status.php?employee=<?= (int)$roomProfile['id'] ?>">View their tasks</a>
            <?php endif; ?>
        <?php else: ?>
            <span class="tc-avatar tc-avatar-xl is-group"><?= icon('people') ?></span>
            <h3 class="tc-info-name"><?= h($roomTitle) ?></h3>
            <p class="tc-info-sub">Group · <?= count($members)+1 ?> members</p>
            <p class="tc-info-label">Members</p>
            <div class="tc-members"><span class="tc-member"><span class="tc-avatar tc-avatar-xs"><?= h($initials($user['full_name'])) ?></span><?= h($user['full_name']) ?> <small>(you)</small></span><?php $canManageGroup=$isStaff || (int)($selected['created_by']??0)===(int)$user['id']; foreach ($members as $member): ?><span class="tc-member"><span class="tc-avatar tc-avatar-xs"><?= h($initials($member['full_name'])) ?></span><span class="tc-member-name"><?= h($member['full_name']) ?></span><?php if ($canManageGroup): ?><form method="post" class="tc-remove-member" onsubmit="return confirm('Remove <?= h(addslashes($member['full_name'])) ?> from this group? They will no longer see its messages.')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>"><input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>"><button type="submit" title="Remove from group" aria-label="Remove <?= h($member['full_name']) ?> from group">×</button></form><?php endif; ?></span><?php endforeach; ?></div>
            <?php $memberIds=array_map(static fn($m)=>(int)$m['id'],$members); $addable=array_values(array_filter($people,static fn($p)=>!in_array((int)$p['id'],$memberIds,true))); if ($addable): ?><button type="button" class="tc-add-members" data-dialog="add-members-dialog"><?= icon('plus') ?> Add people to group</button><?php endif; ?>
        <?php endif; ?>
        <p class="tc-info-label">Shared files</p>
        <div class="tc-nofiles" data-tc-nofiles<?= $sharedFiles?' hidden':'' ?>>No files shared yet</div>
        <div class="tc-shared" data-tc-shared><?php foreach ($sharedFiles as $file): ?><a href="download-chat-attachment.php?id=<?= (int)$file['id'] ?>"><?= icon('document') ?><span><?= h($file['original_file_name']) ?></span><small><?= h(chat_file_size_label((int)$file['file_size_bytes'])) ?></small></a><?php endforeach; ?></div>
    </aside>
    <?php endif; ?>
</div>
</div>

<?php if ($selected && $selected['conversation_type']==='group' && !empty($addable)): ?>
<dialog id="add-members-dialog" class="detail-dialog tc-dialog">
    <div class="dialog-heading"><h2>Add people to <?= h($roomTitle) ?></h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
    <form method="post" class="tc-new-form" data-add-members-form><?= csrf_field() ?><input type="hidden" name="action" value="add_members"><input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>">
        <label class="dept-search"><?= icon('search') ?><span class="sr-only">Search people</span><input type="search" placeholder="Search people…" data-add-search></label>
        <div class="tc-people-bar"><p class="help">Choose one or more people. They will see new messages from now on.</p><button type="button" class="tc-select-all" data-add-all>Select all</button></div>
        <div class="tc-people">
            <?php foreach ($addable as $person): ?><label class="tc-person" data-name="<?= h(mb_strtolower($person['full_name'].' '.$person['department_name'])) ?>"><input type="checkbox" name="user_ids[]" value="<?= (int)$person['id'] ?>"><span class="tc-avatar tc-avatar-sm"><?= h($initials($person['full_name'])) ?></span><span class="tc-person-text"><strong><?= h($person['full_name']) ?></strong><small><?= h($person['department_name'] ?: ucfirst($person['role'])) ?></small></span></label><?php endforeach; ?>
        </div>
        <button class="emp-btn emp-btn-gold" type="submit" data-add-submit disabled>Add to group</button>
    </form>
</dialog>
<script>
(() => {
    const form = document.querySelector('[data-add-members-form]'); if (!form) return;
    const boxes = form.querySelectorAll('input[type=checkbox]'), submit = form.querySelector('[data-add-submit]');
    const count = () => { const n = Array.from(boxes).filter(b => b.checked).length; submit.disabled = n === 0; submit.textContent = n ? 'Add ' + n + (n === 1 ? ' person' : ' people') + ' to group' : 'Add to group'; };
    boxes.forEach(b => b.addEventListener('change', count));
    form.querySelector('[data-add-all]').addEventListener('click', () => { const visible = Array.from(boxes).filter(b => !b.closest('.tc-person').hidden); const all = visible.every(b => b.checked); visible.forEach(b => { b.checked = !all; }); count(); });
    form.querySelector('[data-add-search]').addEventListener('input', e => { const q = e.target.value.trim().toLowerCase(); form.querySelectorAll('.tc-person').forEach(p => { p.hidden = q !== '' && !p.dataset.name.includes(q); }); });
})();
</script>
<?php endif; ?>
<dialog id="new-chat-dialog" class="detail-dialog tc-dialog">
    <div class="dialog-heading"><h2>New chat</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
    <form method="post" class="tc-new-form chat-start-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_conversation">
        <div class="cl-segment tc-type"><label><input type="radio" name="conversation_type" value="private" checked><span>Private chat</span></label><label><input type="radio" name="conversation_type" value="group"><span>Group chat</span></label></div>
        <label id="chat-title-field" hidden>Group name (optional)<input id="chat-title" name="title" maxlength="120" placeholder="e.g. Design team"></label>
        <label class="dept-search"><?= icon('search') ?><span class="sr-only">Search people</span><input type="search" placeholder="Search people…" data-tc-people-search></label>
        <div class="tc-people-bar"><p class="help" id="chat-people-help">Private chat: choose one person.</p><button type="button" class="tc-select-all" data-tc-select-all<?= in_array('employee',array_column($people,'role'),true)?'':' hidden' ?>>Select all employees</button></div>
        <div class="tc-people" data-tc-people>
            <?php foreach ($people as $person): ?><label class="tc-person" data-role="<?= h($person['role']) ?>" data-name="<?= h(mb_strtolower($person['full_name'].' '.$person['department_name'])) ?>"><input type="radio" name="user_ids[]" value="<?= (int)$person['id'] ?>" data-tc-person><span class="tc-avatar tc-avatar-sm"><?= h($initials($person['full_name'])) ?></span><span><strong><?= h($person['full_name']) ?></strong><small><?= h(ucfirst($person['role'])) ?><?= $person['department_name']?' · '.h($person['department_name']):'' ?></small></span></label><?php endforeach; ?>
        </div>
        <button class="emp-btn emp-btn-gold" type="submit">Start chat</button>
    </form>
</dialog>
<script>
(() => {
    const me=<?= (int)$user['id'] ?>, isGroupRoom=<?= $selected && $selected['conversation_type']==='group' ? 'true' : 'false' ?>, selectedId=<?= (int)$selectedId ?>, waBase=<?= json_encode($waBase ?? 'https://wa.me/',JSON_HEX_TAG|JSON_HEX_AMP) ?>, waPhone=<?= json_encode($waPhone ?? '',JSON_HEX_TAG|JSON_HEX_AMP) ?>;
    // WhatsApp: phones open the app via wa.me. On a computer each message has two links:
    // "WhatsApp Web" (one reused Chrome tab) and "App" (WhatsApp Desktop) - use whichever is logged in.
    const isMobile=/Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    if(isMobile){document.documentElement.classList.add('tc-is-mobile');document.querySelectorAll('.tc-wa[data-mode=web]').forEach(a=>{a.textContent='WhatsApp';});}
    const waQuery=text=>(waPhone?'phone='+waPhone+'&':'')+'text='+encodeURIComponent(text);
    let waTab=null;
    const openWhatsApp=(text,mode)=>{
        if(isMobile){window.location.href=waBase+'?text='+encodeURIComponent(text);return;}
        if(mode==='app'){window.location.href='whatsapp://send?'+waQuery(text);return;}
        const url='https://web.whatsapp.com/send?'+waQuery(text);
        if(waTab&&!waTab.closed){waTab.location.href=url;waTab.focus();return;}
        waTab=window.open(url,'bhavi_whatsapp');
        if(!waTab)waToast('Browser blocked the WhatsApp tab. Click WhatsApp Web again.');
    };
    // Media: WhatsApp links cannot carry files. On a computer the first image is copied to the clipboard
    // (paste it in WhatsApp with Ctrl+V) and other files are downloaded (attach them with 📎); then WhatsApp opens.
    // On phones the share sheet sends the files straight to WhatsApp.
    const waToast=msg=>{let t=document.querySelector('.tc-wa-toast');if(!t){t=document.createElement('div');t.className='tc-wa-toast';t.setAttribute('role','status');document.body.append(t);}t.textContent=msg;t.hidden=false;clearTimeout(t._h);t._h=setTimeout(()=>{t.hidden=true;},9000);};
    const fileUrl=f=>`download-chat-attachment.php?id=${f.id}`;
    const downloadFile=f=>{const a=document.createElement('a');a.href=fileUrl(f);a.download=f.name;document.body.append(a);a.click();a.remove();};
    const fetchFile=async f=>{const r=await fetch(fileUrl(f),{credentials:'same-origin'});if(!r.ok)throw new Error('download');return new File([await r.blob()],f.name,{type:f.mime||'application/octet-stream'});};
    const toPng=blob=>new Promise((resolve,reject)=>{const img=new Image(),url=URL.createObjectURL(blob);img.onload=()=>{const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;c.getContext('2d').drawImage(img,0,0);URL.revokeObjectURL(url);c.toBlob(b=>b?resolve(b):reject(new Error('png')),'image/png');};img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('img'));};img.src=url;});
    async function sendMediaToWhatsApp(link,text,files,mode){
        const label=link.textContent;link.textContent='Preparing…';
        try{
            if(isMobile){
                try{const shared=await Promise.all(files.map(fetchFile));const data={files:shared};if(text)data.text=text;if(navigator.canShare&&navigator.canShare(data)){await navigator.share(data);return;}}catch(err){if(err&&err.name==='AbortError')return;}
                files.forEach(downloadFile);waToast('File downloaded. In WhatsApp tap 📎 and attach it.');openWhatsApp(text,mode);return;
            }
            const image=files.find(f=>/^image\/(png|jpe?g|gif|webp|bmp)$/i.test(f.mime||''));
            let copied=false;
            if(image&&navigator.clipboard&&window.ClipboardItem){try{const png=await toPng(await fetchFile(image));await navigator.clipboard.write([new ClipboardItem({'image/png':png})]);copied=true;}catch(_){}}
            const rest=files.filter(f=>!(copied&&f===image));
            rest.forEach(downloadFile);
            waToast(copied?(rest.length?'Image copied: in WhatsApp press Ctrl+V. Other files downloaded: attach them with 📎.':'Image copied: in WhatsApp press Ctrl+V, then Send.'):'File downloaded: in WhatsApp click 📎 and attach it, then Send.');
            openWhatsApp(text,mode);
        }finally{link.textContent=label;}
    }
    document.addEventListener('click',e=>{
        const link=e.target.closest('.tc-wa');if(!link)return;e.preventDefault();
        let files=[];try{files=JSON.parse(link.dataset.files||'[]');}catch(_){}
        const text=link.dataset.text||'',mode=link.dataset.mode||'web';
        if(files.length)sendMediaToWhatsApp(link,text,files,mode);else openWhatsApp(text,mode);
    });
    const icons=<?= json_encode(['people'=>icon('people'),'download'=>icon('download'),'document'=>icon('document')],JSON_HEX_TAG|JSON_HEX_AMP) ?>;
    const initials=n=>(n||'').trim().split(/\s+/).slice(0,2).map(p=>p.charAt(0).toUpperCase()).join('')||'?';
    const el=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;};
    const pad=n=>String(n).padStart(2,'0');
    const localKey=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    const dayLabel=key=>{const today=new Date(),y=new Date();y.setDate(y.getDate()-1);if(key===localKey(today))return'Today';if(key===localKey(y))return'Yesterday';const [yr,mo,da]=key.split('-').map(Number),d=new Date(yr,mo-1,da);return `${d.toLocaleDateString('en-GB',{weekday:'long'})}, ${da} ${d.toLocaleDateString('en-GB',{month:'short'})} ${yr}`;};

    // New chat dialog: private = one person (radio), group = many (checkbox)
    const dialog=document.getElementById('new-chat-dialog');
    if(dialog){
        const titleField=document.getElementById('chat-title-field'),help=document.getElementById('chat-people-help');
        const selectAll=dialog.querySelector('[data-tc-select-all]');
        const employeeInputs=()=>Array.from(dialog.querySelectorAll('.tc-person[data-role="employee"] [data-tc-person]'));
        const isGroup=()=>dialog.querySelector('[name=conversation_type]:checked').value==='group';
        const updateCount=()=>{
            const picked=dialog.querySelectorAll('[data-tc-person]:checked').length,emps=employeeInputs();
            const allPicked=isGroup()&&emps.length>0&&emps.every(i=>i.checked);
            if(selectAll)selectAll.textContent=allPicked?'Clear all employees':'Select all employees';
            help.textContent=isGroup()?(picked?`${picked} selected for this group.`:'Select as many people as you need for this group.'):'Private chat: choose one person.';
        };
        dialog.querySelectorAll('[name=conversation_type]').forEach(r=>r.addEventListener('change',()=>{const group=isGroup();titleField.hidden=!group;dialog.querySelectorAll('[data-tc-person]').forEach(i=>{i.type=group?'checkbox':'radio';i.checked=false;});updateCount();}));
        dialog.querySelector('[data-tc-people]').addEventListener('change',updateCount);
        // "Select all employees" switches to a group chat and ticks every active employee (click again to clear).
        selectAll?.addEventListener('click',()=>{
            if(!isGroup()){const groupRadio=dialog.querySelector('[name=conversation_type][value=group]');groupRadio.checked=true;groupRadio.dispatchEvent(new Event('change'));}
            const emps=employeeInputs(),check=!emps.every(i=>i.checked);
            emps.forEach(i=>{i.checked=check;});
            updateCount();
        });
        dialog.querySelector('[data-tc-people-search]').addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();dialog.querySelectorAll('.tc-person').forEach(p=>{p.hidden=q!==''&&!p.dataset.name.includes(q);});});
        dialog.querySelector('form').addEventListener('submit',e=>{if(!dialog.querySelector('[data-tc-person]:checked')){e.preventDefault();help.textContent='Choose at least one person to start the chat.';}});
    }

    // Chat list: search + tabs + live refresh (preview, time, unread badge, online dot, order)
    let tab='all';
    const list=document.querySelector('.tc-items'),search=document.querySelector('[data-tc-search]');
    const filterList=()=>{const q=(search?.value||'').trim().toLowerCase();document.querySelectorAll('.tc-item').forEach(i=>{i.hidden=(tab!=='all'&&i.dataset.type!==tab)||(q!==''&&!(i.dataset.name||'').includes(q));});};
    search?.addEventListener('input',filterList);
    document.querySelectorAll('[data-tc-tab]').forEach(b=>b.addEventListener('click',()=>{tab=b.dataset.tcTab;document.querySelectorAll('[data-tc-tab]').forEach(x=>x.classList.toggle('active',x===b));filterList();}));
    const buildItem=c=>{
        const group=c.conversation_type==='group',link=el('a','tc-item chat-list-item');
        link.dataset.chatId=c.id;link.dataset.type=c.conversation_type;link.dataset.name=`${c.label} ${c.other_members||''}`.toLowerCase();link.href=`chat.php?id=${c.id}`;
        const av=el('span','tc-avatar'+(group?' is-group':''));if(group)av.innerHTML=icons.people;else av.textContent=initials(c.label);
        const dot=el('i','tc-dot');dot.dataset.tcDot='';dot.hidden=true;av.append(dot);
        const text=el('span','tc-item-text'),preview=el('small');preview.dataset.tcPreview='';text.append(el('strong','',c.label),preview);
        const meta=el('span','tc-item-meta'),when=el('time');when.dataset.tcWhen='';const badge=el('b','tc-unread');badge.dataset.tcUnread='';badge.hidden=true;meta.append(when,badge);
        link.append(av,text,meta);return link;
    };
    async function refreshConversations(){
        if(!list)return;
        try{
            const response=await fetch('chat.php?conversations=1',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;
            const payload=await response.json();
            payload.conversations.forEach((c,index)=>{
                let item=list.querySelector(`[data-chat-id="${c.id}"]`);if(!item)item=buildItem(c);
                const active=Number(c.id)===selectedId,unread=active?0:Number(c.unread)||0;
                item.querySelector('[data-tc-preview]').textContent=c.preview;
                item.querySelector('[data-tc-when]').textContent=c.when||'';
                const badge=item.querySelector('[data-tc-unread]');badge.hidden=!unread;badge.textContent=unread>99?'99+':unread;
                item.classList.toggle('has-unread',unread>0);
                item.querySelector('[data-tc-dot]').hidden=!c.online;
                const items=list.querySelectorAll('.tc-item');if(items[index]!==item)list.insertBefore(item,items[index]||null);
            });
            if(payload.conversations.length)list.querySelector('.tc-none')?.remove();
            filterList();
        }catch(_){}
    }
    window.setInterval(refreshConversations,5000);

    // Details panel toggle (mobile/tablet) + message search
    document.querySelectorAll('[data-tc-info-toggle]').forEach(b=>b.addEventListener('click',()=>document.querySelector('.tc-layout')?.classList.toggle('show-info')));
    const msgSearchWrap=document.querySelector('[data-tc-msg-search-wrap]'),msgSearch=document.querySelector('[data-tc-msg-search]');
    document.querySelector('[data-tc-msg-search-toggle]')?.addEventListener('click',()=>{msgSearchWrap.hidden=!msgSearchWrap.hidden;if(!msgSearchWrap.hidden)msgSearch.focus();else{msgSearch.value='';msgSearch.dispatchEvent(new Event('input'));}});
    msgSearch?.addEventListener('input',()=>{const q=msgSearch.value.trim().toLowerCase();document.querySelectorAll('.tc-msg').forEach(m=>{m.classList.toggle('is-dim',q!==''&&!m.textContent.toLowerCase().includes(q));});});

    // Live messages, read receipts and presence
    const room=document.querySelector('[data-chat-room]'),box=document.getElementById('chat-messages');
    if(!room||!box)return;
    const latest=document.getElementById('chat-latest'),presence=document.querySelector('[data-tc-presence]');
    let lastId=Math.max(0,...Array.from(box.querySelectorAll('[data-message-id]'),item=>Number(item.dataset.messageId)||0));
    let lastDay=Array.from(box.querySelectorAll('.tc-day')).pop()?.dataset.day||'';
    let readUpto=<?= (int)$roomState['read_upto'] ?>;
    const nearBottom=()=>box.scrollHeight-box.scrollTop-box.clientHeight<120;
    const applyReceipts=()=>box.querySelectorAll('.tc-msg.own [data-tc-status]').forEach(s=>{const seen=Number(s.closest('[data-message-id]').dataset.messageId)<=readUpto;s.textContent=seen?'Seen':'Sent';s.classList.toggle('is-seen',seen);});
    const addShared=file=>{const shared=document.querySelector('[data-tc-shared]');if(!shared||shared.querySelector(`[href$="id=${file.id}"]`))return;const a=el('a');a.href=`download-chat-attachment.php?id=${file.id}`;a.innerHTML=icons.document;a.append(el('span','',file.original_file_name),el('small','',file.size_label));shared.prepend(a);document.querySelector('[data-tc-nofiles]')?.setAttribute('hidden','');};
    const append=message=>{
        if(box.querySelector(`[data-message-id="${message.id}"]`))return;
        box.querySelector('.tc-first')?.remove();
        if(message.day!==lastDay){lastDay=message.day;const sep=el('div','tc-day');sep.dataset.day=message.day;sep.append(el('span','',dayLabel(message.day)));box.insertBefore(sep,latest);}
        const own=Number(message.sender_id)===me,item=el('article','tc-msg chat-message'+(own?' own':''));item.dataset.messageId=message.id;if(Number(message.is_deleted)===1)item.dataset.deleted='1';
        if(!own){const av=el('span','tc-avatar tc-avatar-xs',initials(message.sender_name));av.title=message.sender_name;item.append(av);}
        const body=el('div','tc-msg-body');
        if(!own&&isGroupRoom)body.append(el('small','tc-sender',message.sender_name));
        const isDeleted=Number(message.is_deleted)===1;
        if(isDeleted)body.append(el('p','tc-bubble tc-deleted','🚫 This message was deleted'));
        else if(message.body)body.append(el('p','tc-bubble',message.body));
        (isDeleted?[]:(message.files||[])).forEach(file=>{const link=el('a','tc-file chat-attachment');link.href=`download-chat-attachment.php?id=${file.id}`;link.innerHTML=icons.download;link.append(el('span','',file.original_file_name),el('small','',file.size_label));body.append(link);addShared(file);});
        const meta=el('small','tc-meta');meta.append(el('time','',message.time));
        if(own){const st=el('span','tc-status','Sent');st.dataset.tcStatus='';meta.append(' · ',st);}
        const waFiles=JSON.stringify((message.files||[]).map(f=>({id:Number(f.id),name:f.original_file_name,mime:f.mime_type})));
        if(!isDeleted&&(message.body||waFiles!=='[]')){const wa=el('a','tc-wa',isMobile?'WhatsApp':'WhatsApp Web');wa.dataset.files=waFiles;wa.href=waBase+'?text='+encodeURIComponent(message.body||'');wa.target='bhavi_whatsapp';wa.title='Open in WhatsApp Web (Chrome)';wa.dataset.mode='web';wa.dataset.text=message.body||'';const app=el('a','tc-wa','App');app.href='whatsapp://send';app.title='Open in WhatsApp app';app.dataset.mode='app';app.dataset.text=message.body||'';app.dataset.files=waFiles;const appWrap=el('span','tc-wa-app');appWrap.append(' · ',app);meta.append(' · ',wa,appWrap);}
        if(own&&!isDeleted){const del=el('button','tc-del','Delete');del.type='button';del.dataset.tcDelete='';del.title='Delete this message';meta.append(' · ',del);}
        body.append(meta);item.append(body);box.insertBefore(item,latest);
        lastId=Math.max(lastId,Number(message.id)||0);
    };
    // Delete own message: confirm, POST, then show the "deleted" placeholder (others see it on their next refresh).
    const markDeleted=id=>{
        const item=box.querySelector(`[data-message-id="${id}"]`);if(!item||item.dataset.deleted)return;item.dataset.deleted='1';
        const body=item.querySelector('.tc-msg-body');
        body.querySelectorAll('.tc-bubble,.tc-file').forEach(n=>n.remove());
        body.querySelectorAll('.tc-wa,.tc-wa-app,.tc-del').forEach(n=>{const prev=n.previousSibling;if(prev&&prev.nodeType===3&&prev.textContent.trim()==='·')prev.remove();n.remove();});
        body.insertBefore(el('p','tc-bubble tc-deleted','🚫 This message was deleted'),body.querySelector('.tc-meta'));
    };
    box.addEventListener('click',async e=>{
        const btn=e.target.closest('[data-tc-delete]');if(!btn)return;
        if(!confirm('Delete this message for everyone?'))return;
        const id=btn.closest('[data-message-id]').dataset.messageId,data=new FormData();
        data.append('csrf',document.querySelector('[data-chat-compose] [name=csrf]').value);data.append('action','delete_message');data.append('message_id',id);data.append('ajax','1');
        btn.disabled=true;
        try{const r=await fetch('chat.php?id='+selectedId,{method:'POST',body:data,credentials:'same-origin'});const p=await r.json().catch(()=>({}));if(r.ok&&p.ok){markDeleted(id);refreshConversations();}else{alert(p.error||'Message could not be deleted.');btn.disabled=false;}}
        catch(_){alert('Message could not be deleted. Check the connection.');btn.disabled=false;}
    });
    let busy=false;
    async function refresh(forceScroll){
        if(busy)return;busy=true;
        try{
            const stick=forceScroll||nearBottom(),seen=document.visibilityState==='visible'?1:0;
            const response=await fetch(`chat.php?messages=1&id=${room.dataset.conversation}&after=${lastId}&seen=${seen}`,{credentials:'same-origin',cache:'no-store'});
            if(!response.ok)return;
            const payload=await response.json();
            payload.messages.forEach(append);
            (payload.deleted||[]).forEach(markDeleted);
            readUpto=Number(payload.read_upto)||0;applyReceipts();
            if(presence){presence.textContent=payload.presence;presence.classList.toggle('is-online',!!payload.online);}
            if(payload.messages.length&&stick)box.scrollTop=box.scrollHeight;
        }catch(_){}finally{busy=false;}
    }
    box.scrollTop=box.scrollHeight;window.setInterval(()=>refresh(false),3000);
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'){refresh(false);refreshConversations();}});

    // Composer: auto-grow, Enter to send, file names, upload progress
    const compose=document.querySelector('[data-chat-compose]'),textarea=document.getElementById('chat-body'),files=document.getElementById('chat-files'),fileNames=document.getElementById('chat-file-names');
    const grow=()=>{textarea.style.height='auto';textarea.style.height=Math.min(textarea.scrollHeight,140)+'px';};
    textarea?.addEventListener('input',grow);
    textarea?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();compose.requestSubmit();}});
    files?.addEventListener('change',()=>{fileNames.textContent=files.files.length?'📎 '+Array.from(files.files,f=>f.name).join(', '):'';});
    if(compose)compose.addEventListener('submit',event=>{
        event.preventDefault();
        if(!textarea.value.trim()&&!files.files.length)return;
        const xhr=new XMLHttpRequest(),progress=document.getElementById('chat-upload-progress'),status=document.getElementById('chat-upload-status'),button=compose.querySelector('button[type="submit"]');
        const data=new FormData(compose);
        xhr.open('POST',compose.getAttribute('action')||('chat.php?id='+selectedId));button.disabled=true;
        if(files.files.length){progress.hidden=false;status.textContent='Uploading…';}
        xhr.upload.onprogress=e=>{if(e.lengthComputable&&files.files.length){progress.value=Math.round(e.loaded/e.total*100);status.textContent=`Uploading… ${progress.value}%`;}};
        const done=msg=>{button.disabled=false;progress.hidden=true;status.textContent=msg||'';status.classList.toggle('is-error',!!msg);};
        xhr.onload=()=>{
            const serverError=new DOMParser().parseFromString(xhr.responseText,'text/html').querySelector('.alert.error');
            if(serverError){done(serverError.textContent.trim());return;}
            if(xhr.status>=200&&xhr.status<400){textarea.value='';grow();files.value='';fileNames.textContent='';done();refresh(true);refreshConversations();textarea.focus();}
            else done(xhr.status===413?'Upload exceeds the server request size limit.':'Message or file could not be sent. Please try again.');
        };
        xhr.onerror=()=>done('Upload failed. Check the connection and try again.');
        xhr.send(data);
    });
})();
</script>
<?php page_end(); ?>
