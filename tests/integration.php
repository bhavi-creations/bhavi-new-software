<?php
declare(strict_types=1);
// Runs against a uniquely named disposable database, never the application database.
date_default_timezone_set('Asia/Kolkata');
require dirname(__DIR__).'/database/install.php';
$config=require dirname(__DIR__).'/config.php';
$database='bhavi_portal_test_'.bin2hex(random_bytes(6));
$pdo=new PDO("mysql:host={$config['db_host']};port={$config['db_port']};charset=utf8mb4",$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+05:30'");
$process=null; $temporary=[]; $checks=0;
function expect(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
function scalar(string $sql,array $params=[]): mixed { global $pdo; $stmt=$pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
final class Browser
{
    public string $csrf='';
    private string $cookie;
    public function __construct(private string $base) { global $temporary; $this->cookie=tempnam(sys_get_temp_dir(),'bhavi_cookie_'); $temporary[]=$this->cookie; }
    public function request(string $path,?array $data=null): array {
        $handle=curl_init($this->base.'/'.$path); $headers=[];
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>20,CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){ $parts=explode(':',$line,2); if (count($parts)===2) $headers[strtolower(trim($parts[0]))]=trim($parts[1]); return strlen($line); }]);
        if ($data!==null) { curl_setopt($handle,CURLOPT_POST,true); curl_setopt($handle,CURLOPT_POSTFIELDS,array_filter($data,static fn($v)=>$v instanceof CURLFile)?$data:http_build_query($data)); }
        $body=curl_exec($handle); if ($body===false) throw new RuntimeException(curl_error($handle));
        $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE); unset($handle);
        if (preg_match('/name="csrf" value="([a-f0-9]{64})"/',$body,$match)) $this->csrf=$match[1];
        if ($status>=500 || str_contains($body,'Fatal error') || str_contains($body,'Warning:')) throw new RuntimeException($path.' returned '.$status.': '.substr(strip_tags($body),0,400));
        return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }
    public function post(string $path,array $data): array { return $this->request($path,['csrf'=>$this->csrf,...$data]); }
    public function login(string $username,string $password,string $dashboard): void {
        $this->request('login.php'); $response=$this->post('authenticate.php',['username'=>$username,'password'=>$password]);
        expect($response['status']===303 && $response['headers']['location']===$dashboard,'Login redirect for '.$username);
        expect($this->request($dashboard)['status']===200,'Dashboard renders for '.$username);
    }
}
try {
    portal_install($pdo,$database); portal_install($pdo,$database);
    expect((int)scalar('SELECT COUNT(*) FROM departments')===5,'Idempotent schema installation');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException($error);
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $log=tempnam(sys_get_temp_dir(),'bhavi_server_'); $temporary[]=$log;
    $environment=getenv(); $environment['BHAVI_DB_NAME']=$database;
    $process=proc_open([PHP_BINARY,'-S',$address,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the test server.');
    fclose($pipes[0]);
    $ready=false; for ($i=0;$i<50;$i++) { $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if ($connection) { fclose($connection); $ready=true; break; } usleep(100000); }
    if (!$ready) throw new RuntimeException('Test server did not start: '.file_get_contents($log));
    $base='http://'.$address; $guest=new Browser($base);
    expect($guest->request('index.php')['headers']['location']==='setup.php','Fresh installation opens setup');
    $guest->request('setup.php');
    $password='Integration-Test-2026!';
    expect($guest->post('setup.php',['admin_name'=>'Test Administrator','admin_username'=>'admin.test','admin_password'=>$password,'manager_name'=>'Test Manager','manager_username'=>'manager.test','manager_password'=>$password])['status']===303,'Initial account creation');
    expect((int)scalar('SELECT COUNT(*) FROM users')===2,'Setup creates exactly two accounts');
    expect($guest->request('setup.php')['headers']['location']==='login.php','Setup closes after accounts exist');
    $routes=['admin-dashboard.php','manager-dashboard.php','admin-employees.php','add-client.php','admin-holidays.php','manager-holidays.php','apply-leaves.php','check-leave.php','manager-dailywork.php','manager-leave-requist.php','manager-notification.php','employee-brands-assets.php','client-reuirement.php','website-employee-dashboard.php','seo-employee-dashboard.php','design-employee-dashboard.php','socialmedia-employee-dashboard.php','telecaller-employee-dashboard.php','manager-assign-work.php','manager-review-work.php','download-work.php','download-asset.php'];
    foreach ($routes as $route) expect($guest->request($route)['headers']['location']==='login.php','Unauthenticated route '.$route);
    $guest->request('login.php');
    expect($guest->post('authenticate.php',['username'=>'admin.test','password'=>'bad-password'])['headers']['location']==='login.php','Bad password rejected');
    expect(str_contains($guest->request('login.php')['body'],'Incorrect username or password'),'Login error appears only after failure');
    expect(!str_contains($guest->request('login.php')['body'],'Incorrect username or password'),'Login error clears');
    $admin=new Browser($base); $admin->login('admin.test',$password,'admin-dashboard.php');
    $manager=new Browser($base); $manager->login('manager.test',$password,'manager-dashboard.php');
    expect($admin->request('manager-dashboard.php')['status']===403,'Admin dashboard role separation');
    expect($manager->request('admin-dashboard.php')['status']===403,'Manager cannot open admin dashboard');
    expect($manager->request('admin-add-employee.php')['status']===403,'Manager cannot create users');
    expect($admin->request('admin-add-employee.php', ['csrf'=>'invalid'])['status']===419,'CSRF rejected');
    $employeeIds=[]; $browsers=[];
    $dashboards=['website'=>'website-employee-dashboard.php','seo'=>'seo-employee-dashboard.php','design_video'=>'design-employee-dashboard.php','telecaller'=>'telecaller-employee-dashboard.php','social_media'=>'socialmedia-employee-dashboard.php'];
    foreach ($dashboards as $code=>$dashboard) {
        $admin->request('admin-add-employee.php');
        $response=$admin->post('admin-add-employee.php',['employee_name'=>'Employee '.$code,'email'=>$code.'@example.test','username'=>$code.'.test','temporary_password'=>$password,'role'=>'employee','account_status'=>'active','department_id'=>scalar('SELECT id FROM departments WHERE code=?',[$code]),'designation'=>'Team member','joining_date'=>date('Y-m-d'),'manager_id'=>'']);
        expect($response['status']===303,'Create '.$code.' employee');
        $employeeIds[$code]=(int)scalar('SELECT id FROM users WHERE username=?',[$code.'.test']);
        expect(password_verify($password,(string)scalar('SELECT password_hash FROM users WHERE id=?',[$employeeIds[$code]])),'Password stored as hash');
        $browsers[$code]=new Browser($base); $browsers[$code]->login($code.'.test',$password,$dashboard);
        expect($browsers[$code]->request('admin-dashboard.php')['status']===403,'Employee forbidden admin dashboard');
        expect($browsers[$code]->request('manager-dailywork.php')['status']===403,'Employee forbidden team reports');
        expect($browsers[$code]->request('download-work.php')['status']===403,'Employee forbidden team export');
    }
    expect(str_contains($manager->request('manager-dashboard.php')['body'],'<strong>5</strong>'),'Manager total uses database employees');
    expect($browsers['seo']->request('website-employee-dashboard.php')['headers']['location']==='seo-employee-dashboard.php','Wrong department route redirects');
    expect(str_contains($browsers['website']->request('admin-employees.php')['body'],'Employee seo'),'Employee sees shared employee directory');
    $employeeEdit=['employee_name'=>'Employee website','email'=>'website@example.test','username'=>'website.test','temporary_password'=>'','role'=>'admin','account_status'=>'active','department_id'=>scalar("SELECT id FROM departments WHERE code='website'"),'designation'=>'Senior developer','joining_date'=>date('Y-m-d'),'manager_id'=>''];
    $manager->request('admin-add-employee.php?id='.$employeeIds['website']);
    expect($manager->post('admin-add-employee.php?id='.$employeeIds['website'],$employeeEdit)['status']===303,'Manager edits employee');
    expect(scalar('SELECT designation FROM employee_profiles WHERE user_id=?',[$employeeIds['website']])==='Senior developer','Employee edit persists');
    expect(scalar('SELECT role FROM users WHERE id=?',[$employeeIds['website']])==='employee','Employee form cannot elevate a role');
    expect(password_verify($password,(string)scalar('SELECT password_hash FROM users WHERE id=?',[$employeeIds['website']])),'Blank edit password keeps existing password');
    $manager->request('admin-add-employee.php?id='.$employeeIds['website']);
    expect($manager->post('admin-add-employee.php?id='.$employeeIds['website'],[...$employeeEdit,'account_status'=>'inactive'])['status']===303,'Employee account can be deactivated');
    expect($browsers['website']->request($dashboards['website'])['headers']['location']==='login.php','Inactive account loses access');
    $manager->request('admin-add-employee.php?id='.$employeeIds['website']);
    expect($manager->post('admin-add-employee.php?id='.$employeeIds['website'],$employeeEdit)['status']===303,'Employee account can be reactivated');
    $browsers['website']->login('website.test',$password,$dashboards['website']);
    $admin->request('admin-add-employee.php');
    expect($admin->post('admin-add-employee.php',['employee_name'=>'Duplicate','email'=>'','username'=>'website.test','temporary_password'=>$password,'role'=>'employee','account_status'=>'active','department_id'=>scalar("SELECT id FROM departments WHERE code='website'"),'designation'=>'Developer','joining_date'=>date('Y-m-d'),'manager_id'=>''])['status']===200,'Duplicate username produces form error');
    expect((int)scalar("SELECT COUNT(*) FROM users WHERE role='employee'")===5,'Duplicate account transaction rolls back');
    $admin->request('admin-add-client.php');
    expect($admin->post('admin-add-client.php',['client_name'=>'Unsafe','website_url'=>'javascript:alert(1)'])['status']===200,'Unsafe client URL rejected');
    $image=tempnam(sys_get_temp_dir(),'bhavi_logo_'); $temporary[]=$image;
    file_put_contents($image,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    expect($admin->post('admin-add-client.php',['client_name'=>'Shared Client','website_url'=>'https://example.com','client_logo'=>new CURLFile($image,'image/png','logo.png')])['status']===303,'Client and logo upload');
    $clientId=(int)scalar("SELECT id FROM clients WHERE client_name='Shared Client'");
    $uploadedLogo=(string)scalar('SELECT logo_path FROM clients WHERE id=?',[$clientId]);
    if (preg_match('~^uploads/logos/[a-f0-9]{32}\.png$~D',$uploadedLogo)) $temporary[]=dirname(__DIR__).'/'.$uploadedLogo;
    foreach ([$manager,...array_values($browsers)] as $browser) expect(str_contains($browser->request('add-client.php')['body'],'Shared Client'),'Client visible across roles');
    $admin->request('admin-add-client.php?id='.$clientId);
    expect($admin->post('admin-add-client.php?id='.$clientId,['client_name'=>'Shared Client Updated','website_url'=>'https://example.com/new'])['status']===303,'Client edit');
    expect(scalar('SELECT client_name FROM clients WHERE id=?',[$clientId])==='Shared Client Updated','Edited client persists');
    $admin->request('admin-holidays.php'); $holidayDate=date('Y-m-d',strtotime('+20 days'));
    expect($admin->post('admin-holidays.php',['action'=>'save_holiday','holiday_name'=>'Team Holiday <b>','holiday_date'=>$holidayDate,'description'=>'Company holiday'])['status']===303,'Holiday creation');
    $holidayId=(int)scalar('SELECT id FROM holidays WHERE holiday_date=?',[$holidayDate]);
    foreach ([$manager,...array_values($browsers)] as $browser) { $body=$browser->request('admin-holidays.php')['body']; expect(str_contains($body,'Team Holiday &lt;b&gt;') && !str_contains($body,'Team Holiday <b>'),'Shared holiday and output escaping'); }
    $manager->request('admin-holidays.php?edit='.$holidayId);
    expect($manager->post('admin-holidays.php?edit='.$holidayId,['action'=>'save_holiday','holiday_name'=>'Updated Holiday','holiday_date'=>$holidayDate,'description'=>'Edited by manager'])['status']===303,'Manager holiday editing');
    $manager->request('manager-assign-work.php'); $websiteDepartment=(int)scalar("SELECT id FROM departments WHERE code='website'");
    $assignmentData=['department_id'=>$websiteDepartment,'employee_id'=>$employeeIds['website'],'client_id'=>$clientId,'work_date'=>date('Y-m-d'),'title'=>'Build home page','description'=>'Responsive page with contact form'];
    expect($manager->post('manager-assign-work.php',[...$assignmentData,'employee_id'=>$employeeIds['seo']])['status']===200,'Employee department mismatch rejected');
    expect((int)scalar('SELECT COUNT(*) FROM work_assignments')===0,'Invalid assignment never saved');
    expect($manager->post('manager-assign-work.php',$assignmentData)['status']===303,'Manager assigns work');
    $assignmentId=(int)scalar('SELECT id FROM work_assignments LIMIT 1');
    expect(str_contains($browsers['website']->request($dashboards['website'])['body'],'Build home page'),'Assignment visible to its employee');
    expect(!str_contains($browsers['seo']->request($dashboards['seo'])['body'],'Build home page'),'Assignment hidden from another employee');
    expect($browsers['seo']->post($dashboards['seo'],['action'=>'update_assignment','assignment_id'=>$assignmentId,'task_status'=>'completed','remark'=>'Attack'])['status']===403,'Assignment ownership enforced');
    $website=$browsers['website']; $website->request($dashboards['website']);
    $update=['action'=>'update_assignment','assignment_id'=>$assignmentId,'task_status'=>'completed','remark'=>'=SUM(1,2)','website_new_count'=>'2','website_changes_count'=>'1'];
    expect($website->post($dashboards['website'],$update)['status']===303,'Employee task update');
    $website->request($dashboards['website']);
    expect($website->post($dashboards['website'],$update)['status']===303,'Repeated update');
    expect((int)scalar('SELECT COUNT(*) FROM daily_work_entries WHERE assignment_id=?',[$assignmentId])===1,'Repeated task update does not duplicate report rows');
    expect(scalar('SELECT status FROM work_assignments WHERE id=?',[$assignmentId])==='completed','Task status persists');
    foreach ($dashboards as $code=>$dashboard) {
        $browser=$browsers[$code]; $browser->request($dashboard);
        $metrics=match($code) { 'website'=>['website_new_count'=>'1','website_changes_count'=>'0'],'seo'=>['seo_quantity'=>'3.50','seo_quantity_unit'=>'pages'],'design_video'=>['video_count'=>'1','poster_count'=>'2','carousel_count'=>'1','design_changes_count'=>'0'],'telecaller'=>['calls_count'=>'10','connected_count'=>'5','followups_count'=>'3','leads_count'=>'2'],'social_media'=>['social_platform'=>'Instagram','posts_count'=>'2','reels_count'=>'1','replies_count'=>'6'] };
        $work=['action'=>'save_entry','client_id'=>$clientId,'task_title'=>$code.' daily task','task_status'=>'completed','remark'=>'Daily '.$code,'submit_mode'=>'submitted',...$metrics];
        expect($browser->post($dashboard,$work)['status']===303,'Department work submission '.$code);
    }
    $tele=$browsers['telecaller']; $tele->request($dashboards['telecaller']);
    expect($tele->post($dashboards['telecaller'],['action'=>'save_entry','client_id'=>$clientId,'task_title'=>'Bad counts','task_status'=>'pending','remark'=>'','calls_count'=>'1','connected_count'=>'2','followups_count'=>'0','leads_count'=>'0'])['status']===200,'Invalid telecaller quantities rejected');
    $yesterday=date('Y-m-d',strtotime('-1 day')); $website->request($dashboards['website'].'?date='.$yesterday);
    expect($website->post($dashboards['website'].'?date='.$yesterday,['action'=>'save_entry','client_id'=>$clientId,'task_title'=>'Yesterday page','task_status'=>'pending','remark'=>'Historic work','website_new_count'=>'1','website_changes_count'=>'0','submit_mode'=>'draft'])['status']===303,'Draft save');
    expect(scalar('SELECT submission_status FROM daily_work_submissions WHERE employee_id=? AND work_date=?',[$employeeIds['website'],$yesterday])==='draft','Draft status persists');
    $website->request($dashboards['website'].'?date='.$yesterday);
    expect($website->post($dashboards['website'].'?date='.$yesterday,['action'=>'submit_day'])['status']===303,'Submit entire day');
    $manager->request('manager-dailywork.php');
    $all=$manager->request('manager-dailywork.php')['body'];
    expect(str_contains($all,'website daily task') && str_contains($all,'seo daily task'),'All submitted departments appear in report details');
    $filtered=$manager->request('manager-dailywork.php?department='.$websiteDepartment)['body'];
    expect(str_contains($filtered,'website daily task') && !str_contains($filtered,'seo daily task'),'Department report filtering');
    expect(!str_contains($filtered,'>Employee seo</option>'),'Category dropdown lists matching employees');
    expect($manager->request('manager-dailywork.php?from_date=invalid')['status']===422,'Invalid report date rejected');
    $csv=$manager->request('download-work.php?from_date='.$yesterday.'&to_date='.date('Y-m-d').'&department='.$websiteDepartment);
    expect(str_contains($csv['headers']['content-type'],'text/csv') && str_contains($csv['headers']['content-disposition'],'.csv'),'CSV download headers');
    expect(str_contains($csv['body'],'Yesterday page') && str_contains($csv['body'],'website daily task') && !str_contains($csv['body'],'seo daily task'),'CSV range and department filtering');
    expect(str_contains($csv['body'],"'=SUM(1,2)"),'CSV formula injection escaped');
    $employeeCsv=$manager->request('download-work.php?employee='.$employeeIds['seo']);
    expect(str_contains($employeeCsv['body'],'seo daily task') && !str_contains($employeeCsv['body'],'website daily task'),'Employee CSV filter');
    $sheetId=(int)scalar('SELECT id FROM daily_work_submissions WHERE employee_id=? AND work_date=?',[$employeeIds['website'],date('Y-m-d')]);
    $manager->request('manager-review-work.php?id='.$sheetId);
    expect($manager->post('manager-review-work.php?id='.$sheetId,['action'=>'review_sheet','review_status'=>'changes_requested','manager_remark'=>'Please update the mobile header'])['status']===303,'Manager reviews work');
    expect(str_contains($website->request($dashboards['website'])['body'],'Please update the mobile header'),'Employee sees manager feedback');
    $entryId=(int)scalar('SELECT id FROM daily_work_entries WHERE assignment_id=?',[$assignmentId]);
    $manager->request('manager-review-work.php?id='.$sheetId);
    expect($manager->post('manager-review-work.php?id='.$sheetId,['action'=>'edit_entry','entry_id'=>$entryId,'task_title'=>'Reviewed home page','task_status'=>'pending','remark'=>'Needs revision','website_new_count'=>'1','website_changes_count'=>'2'])['status']===303,'Manager edits submitted work');
    expect(scalar('SELECT status FROM work_assignments WHERE id=?',[$assignmentId])==='pending','Manager work edit synchronizes assignment');
    $leaveDate=(new DateTimeImmutable('next tuesday'))->format('Y-m-d');
    $website->request('apply-leaves.php');
    $leaveData=['leave_type_id'=>'1','from_date'=>$leaveDate,'to_date'=>$leaveDate,'reason'=>'Family appointment'];
    expect($website->post('apply-leaves.php',$leaveData)['status']===303,'Employee applies leave');
    $leaveId=(int)scalar('SELECT id FROM leave_requests WHERE employee_id=? ORDER BY id DESC LIMIT 1',[$employeeIds['website']]);
    $website->request('apply-leaves.php');
    expect($website->post('apply-leaves.php',$leaveData)['status']===200,'Overlapping leave rejected');
    expect(str_contains($manager->request('manager-leave-requist.php')['body'],'Family appointment'),'Manager sees leave request');
    expect($manager->post('manager-leave-requist.php',['id'=>$leaveId,'decision'=>'approved','decision_note'=>'Approved by manager'])['status']===303,'Manager approves leave');
    expect(scalar('SELECT status FROM leave_requests WHERE id=?',[$leaveId])==='approved','Approval persists');
    expect(str_contains($website->request('apply-leaves.php')['body'],'Approved by manager'),'Employee sees leave decision');
    expect(str_contains($website->request('check-leave.php?month='.substr($leaveDate,0,7))['body'],'Personal · approved'),'Leave calendar uses stored approval');
    $manager->request('manager-leave-requist.php');
    expect($manager->post('manager-leave-requist.php',['id'=>$leaveId,'decision'=>'rejected','decision_note'=>'Second decision'])['status']===200,'Repeat leave decision rejected');
    expect(scalar('SELECT status FROM leave_requests WHERE id=?',[$leaveId])==='approved','Original decision preserved');
    $seo=$browsers['seo']; $seo->request('apply-leaves.php');
    expect($seo->post('apply-leaves.php',[...$leaveData,'reason'=>'SEO leave'])['status']===303,'Second employee leave');
    $seoLeave=(int)scalar('SELECT id FROM leave_requests WHERE employee_id=? ORDER BY id DESC LIMIT 1',[$employeeIds['seo']]);
    $manager->request('manager-leave-requist.php');
    expect($manager->post('manager-leave-requist.php',['id'=>$seoLeave,'decision'=>'rejected','decision_note'=>'Discuss new dates'])['status']===303,'Manager rejects leave');
    expect(str_contains($seo->request('apply-leaves.php')['body'],'Discuss new dates'),'Rejected decision visible');
    expect(!str_contains($seo->request('apply-leaves.php')['body'],'Family appointment'),'Leave history is private');
    $seo->request('apply-leaves.php');
    expect($seo->post('apply-leaves.php',[...$leaveData,'reason'=>'Request to cancel'])['status']===303,'Leave can be reapplied after rejection');
    $cancelId=(int)scalar('SELECT id FROM leave_requests WHERE employee_id=? ORDER BY id DESC LIMIT 1',[$employeeIds['seo']]);
    $website->request('apply-leaves.php');
    expect($website->post('apply-leaves.php',['action'=>'cancel_leave','id'=>$cancelId])['status']===200,'Employee cannot cancel another employee leave');
    $seo->request('apply-leaves.php');
    expect($seo->post('apply-leaves.php',['action'=>'cancel_leave','id'=>$cancelId])['status']===303,'Employee can cancel own pending request');
    expect(scalar('SELECT status FROM leave_requests WHERE id=?',[$cancelId])==='cancelled','Cancelled leave persists');
    $website->request('employee-brands-assets.php');
    expect(str_contains($website->request('client-reuirement.php')['body'],'Responsive page with contact form'),'Client requirements show assigned brief');
    if ($browserPath=getenv('BHAVI_BROWSER')) {
        $browserProcess=proc_open(['node',__DIR__.'/browser-smoke.cjs',$browserPath,$base,$password],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$browserPipes,dirname(__DIR__));
        if (!is_resource($browserProcess)) throw new RuntimeException('Unable to start browser checks.');
        fclose($browserPipes[0]);
        expect(proc_close($browserProcess)===0,'Interactive browser checks');
    }
    foreach ($routes as $route) if (!in_array($route,['manager-review-work.php','download-asset.php'],true) && !str_contains($route,'employee-dashboard') && !in_array($route,['apply-leaves.php','check-leave.php','admin-dashboard.php'],true)) expect($manager->request($route)['status']===200,'Manager page '.$route);
    $manager->request('manager-dailywork.php');
    $historicSheet=(int)scalar('SELECT id FROM daily_work_submissions WHERE employee_id=? AND work_date=?',[$employeeIds['website'],$yesterday]);
    expect($manager->post('manager-dailywork.php',['action'=>'delete_submission','id'=>$historicSheet])['status']===303,'Manager deletes work report');
    expect((int)scalar('SELECT COUNT(*) FROM daily_work_entries WHERE submission_id=?',[$historicSheet])===0,'Deleted report rows removed');
    $manager->request('manager-assign-work.php');
    expect($manager->post('manager-assign-work.php',['action'=>'delete_assignment','id'=>$assignmentId])['status']===303,'Assignment deletion');
    expect(!str_contains($website->request($dashboards['website'])['body'],'Responsive page with contact form'),'Deleted task is no longer assigned');
    $admin->request('admin-holidays.php'); expect($admin->post('admin-holidays.php',['action'=>'delete_holiday','id'=>$holidayId])['status']===303,'Holiday delete');
    expect((int)scalar('SELECT is_published FROM holidays WHERE id=?',[$holidayId])===0,'Deleted holiday hidden');
    $admin->request('add-client.php'); expect($admin->post('add-client.php',['action'=>'delete_client','id'=>$clientId])['status']===303,'Client delete');
    expect(!str_contains($website->request('add-client.php')['body'],'Shared Client Updated'),'Deleted client hidden from employee');
    $admin->request('admin-employees.php'); expect($admin->post('admin-employees.php',['action'=>'delete_employee','id'=>$employeeIds['social_media']])['status']===303,'Employee account deletion');
    expect($browsers['social_media']->request($dashboards['social_media'])['headers']['location']==='login.php','Deleted employee session loses access');
    expect(str_contains($manager->request('manager-dashboard.php')['body'],'<strong>4</strong>'),'Employee dashboard total updates after deletion');
    $website->request('change-password.php');
    $newPassword='  New-Password-Test!  ';
    expect($website->post('change-password.php',['current_password'=>$password,'new_password'=>$newPassword,'confirm_password'=>$newPassword])['status']===303,'Employee changes password');
    expect(password_verify($newPassword,(string)scalar('SELECT password_hash FROM users WHERE id=?',[$employeeIds['website']])),'Password whitespace is preserved');
    $website->request($dashboards['website']);
    expect($website->request('logout.php')['status']===405,'Logout requires POST');
    expect($website->post('logout.php',[])['headers']['location']==='login.php','Logout works');
    expect($website->request($dashboards['website'])['headers']['location']==='login.php','Protected route after logout');
    $website->login('website.test',$newPassword,$dashboards['website']);
    echo "PASS: $checks integration checks (authentication, roles, departments, CRUD, assignments, submissions, reports, CSV, reviews, leaves and logout).\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");
    if (isset($log) && is_file($log)) fwrite(STDERR,substr(file_get_contents($log),-3500));
    $failed=true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    // Only drop the test database created by this process.
    if (preg_match('/^bhavi_portal_test_[a-f0-9]{12}$/D',$database)) $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($temporary as $file) if (is_file($file)) unlink($file);
}
exit(isset($failed)?1:0);
