-- Reference data required by the application (safe to re-run)
SET NAMES utf8mb4;

INSERT IGNORE INTO roles (role_key, name) VALUES
('super_admin','Super Admin'),('owner','Class Owner'),('teacher','Teacher'),('student','Student'),('parent','Parent');

INSERT IGNORE INTO permissions (perm_key, name, module, is_financial) VALUES
('dashboard.view','View dashboard','dashboard',0),
('students.view','View students','students',0),
('students.manage','Add / edit students','students',0),
('documents.manage','Upload and view student documents','students',0),
('admissions.manage','Approve online admissions','students',0),
('teachers.manage','Manage teachers','teachers',0),
('structure.manage','Manage activities, batches, branches','structure',0),
('attendance.mark','Mark attendance','attendance',0),
('attendance.correct','Correct past attendance','attendance',0),
('fees.view','View fees and dues','fees',1),
('fees.manage','Configure fee plans and invoices','fees',1),
('fees.collect','Collect fee payments','fees',1),
('payments.reverse','Reverse or refund payments','fees',1),
('reports.view','View reports','reports',0),
('reports.financial','View financial reports','reports',1),
('notifications.send','Send reminders and announcements','notifications',0),
('settings.manage','Manage academy settings','settings',0);

-- Owners implicitly hold every permission. Teachers get these defaults; owner can grant more per teacher.
INSERT IGNORE INTO role_permissions (role_key, perm_key) VALUES
('teacher','dashboard.view'),('teacher','students.view'),('teacher','attendance.mark');

INSERT IGNORE INTO packages (id, name, slug, description, price_paise, billing_cycle, trial_days, student_limit, teacher_limit, activity_limit, batch_limit, branch_limit, storage_limit_mb, monthly_whatsapp_limit, is_featured, sort_order) VALUES
(1,'Starter','starter','For small classes getting organised.',49900,'monthly',14,75,3,3,6,1,500,0,0,1),
(2,'Professional','professional','Automated reminders, WhatsApp and reports for growing academies.',119900,'monthly',14,300,15,10,30,1,2048,2000,1,2),
(3,'Enterprise','enterprise','Multiple branches, advanced automation and priority support.',299900,'monthly',14,NULL,NULL,NULL,NULL,10,10240,10000,0,3);

INSERT IGNORE INTO package_features (package_id, feature_key, enabled) VALUES
(1,'attendance',1),(1,'fees',1),(1,'inapp',1),(1,'manual_reminders',1),(1,'parent_access',1),(1,'push',1),
(2,'attendance',1),(2,'fees',1),(2,'inapp',1),(2,'manual_reminders',1),(2,'parent_access',1),(2,'push',1),
(2,'auto_reminders',1),(2,'whatsapp',1),(2,'email',1),(2,'reports',1),(2,'documents',1),(2,'online_admission',1),(2,'online_payments',1),(2,'staff_management',1),
(3,'attendance',1),(3,'fees',1),(3,'inapp',1),(3,'manual_reminders',1),(3,'parent_access',1),(3,'push',1),
(3,'auto_reminders',1),(3,'whatsapp',1),(3,'email',1),(3,'sms',1),(3,'reports',1),(3,'advanced_reports',1),(3,'documents',1),
(3,'online_admission',1),(3,'online_payments',1),(3,'staff_management',1),(3,'custom_templates',1),(3,'multi_branch',1),(3,'api_access',1),(3,'priority_support',1);

INSERT IGNORE INTO reminder_rules (academy_id, offset_days, is_enabled) VALUES
(NULL,-7,1),(NULL,-3,1),(NULL,-1,1),(NULL,0,1),(NULL,3,1),(NULL,7,1);

-- Platform default templates. WhatsApp templates must be approved in Meta Business Manager
-- with the same name and the variables in the order given in wa_params.
INSERT IGNORE INTO notification_templates (academy_id, event_key, channel, title, body, wa_template_name, wa_language, wa_params) VALUES
(NULL,'admission_confirmation','whatsapp','Admission confirmed','Welcome {student_name}! Admission to {activity_name} at {academy_name} is confirmed. Student ID: {student_code}.','classnex_admission','en','["student_name","activity_name","academy_name","student_code"]'),
(NULL,'admission_confirmation','inapp','Admission confirmed','Welcome {student_name}! Admission to {activity_name} at {academy_name} is confirmed. Student ID: {student_code}.',NULL,NULL,NULL),
(NULL,'admission_confirmation','push','Admission confirmed','{student_name} is now enrolled in {activity_name}.',NULL,NULL,NULL),
(NULL,'admission_confirmation','email','Admission confirmed – {academy_name}','Dear {recipient_name},\n\nAdmission of {student_name} to {activity_name} at {academy_name} is confirmed.\nStudent ID: {student_code}\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'attendance_present','whatsapp','Attendance','{student_name} was marked Present today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_present','inapp','Present today','{student_name} was marked Present on {date}.',NULL,NULL,NULL),
(NULL,'attendance_present','push','Present today','{student_name} was marked Present on {date}.',NULL,NULL,NULL),
(NULL,'attendance_absent','whatsapp','Attendance','{student_name} was marked Absent today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_absent','inapp','Absent today','{student_name} was marked Absent on {date}.',NULL,NULL,NULL),
(NULL,'attendance_absent','push','Absent today','{student_name} was marked Absent on {date}.',NULL,NULL,NULL),
(NULL,'attendance_late','whatsapp','Attendance','{student_name} was marked Late today ({date}) at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'attendance_late','inapp','Late today','{student_name} was marked Late on {date}.',NULL,NULL,NULL),
(NULL,'attendance_late','push','Late today','{student_name} was marked Late on {date}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','inapp','Attendance updated','Attendance of {student_name} for {date} was updated to {status}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','push','Attendance updated','Attendance of {student_name} for {date} was updated to {status}.',NULL,NULL,NULL),
(NULL,'attendance_corrected','whatsapp','Attendance updated','Attendance of {student_name} for {date} was updated to {status} at {academy_name}.','classnex_attendance','en','["student_name","status","date","academy_name"]'),
(NULL,'fee_due','whatsapp','Fee reminder','Dear Parent, the {fee_title} of {amount} for {student_name} is due on {due_date}. Please complete the payment. Thank you – {academy_name}.','classnex_fee_due','en','["fee_title","amount","student_name","due_date","academy_name"]'),
(NULL,'fee_due','inapp','Fee due on {due_date}','{fee_title} of {amount} for {student_name} is due on {due_date}.',NULL,NULL,NULL),
(NULL,'fee_due','push','Fee due on {due_date}','{fee_title} of {amount} for {student_name} is due on {due_date}.',NULL,NULL,NULL),
(NULL,'fee_due','email','Fee reminder – {student_name}','Dear {recipient_name},\n\nThe {fee_title} of {amount} for {student_name} is due on {due_date}.\nPlease complete the payment.\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'fee_due','sms','Fee reminder','{fee_title} of {amount} for {student_name} is due on {due_date}. -{academy_name}',NULL,NULL,NULL),
(NULL,'fee_overdue','whatsapp','Fee overdue','Dear Parent, the {fee_title} of {amount} for {student_name} was due on {due_date} and is still pending. Please pay at the earliest. – {academy_name}','classnex_fee_overdue','en','["fee_title","amount","student_name","due_date","academy_name"]'),
(NULL,'fee_overdue','inapp','Fee overdue','{fee_title} of {amount} for {student_name} was due on {due_date} and is pending.',NULL,NULL,NULL),
(NULL,'fee_overdue','push','Fee overdue','{fee_title} of {amount} for {student_name} is overdue.',NULL,NULL,NULL),
(NULL,'fee_overdue','email','Fee overdue – {student_name}','Dear {recipient_name},\n\nThe {fee_title} of {amount} for {student_name} was due on {due_date} and is still pending.\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'fee_overdue','sms','Fee overdue','{fee_title} of {amount} for {student_name} is overdue since {due_date}. -{academy_name}',NULL,NULL,NULL),
(NULL,'payment_success','whatsapp','Payment received','Payment of {amount} received for {student_name}. Receipt No: {receipt_no}. Balance: {balance}. Download: {receipt_link} – {academy_name}','classnex_payment','en','["amount","student_name","receipt_no","balance","receipt_link","academy_name"]'),
(NULL,'payment_success','inapp','Payment received','Payment of {amount} received for {student_name}. Receipt {receipt_no}. Balance: {balance}.',NULL,NULL,NULL),
(NULL,'payment_success','push','Payment received','{amount} received for {student_name}. Receipt {receipt_no}.',NULL,NULL,NULL),
(NULL,'payment_success','email','Payment receipt {receipt_no}','Dear {recipient_name},\n\nWe have received {amount} for {student_name}.\nReceipt No: {receipt_no}\nBalance: {balance}\nDownload receipt: {receipt_link}\n\nThank you,\n{academy_name}',NULL,NULL,NULL),
(NULL,'batch_change','whatsapp','Batch changed','{student_name} has been moved to batch {batch_name} ({activity_name}) at {academy_name}.','classnex_general','en','["message"]'),
(NULL,'batch_change','inapp','Batch changed','{student_name} has been moved to batch {batch_name} ({activity_name}).',NULL,NULL,NULL),
(NULL,'batch_change','push','Batch changed','{student_name} moved to {batch_name}.',NULL,NULL,NULL),
(NULL,'class_cancelled','whatsapp','Class cancelled','{message}','classnex_general','en','["message"]'),
(NULL,'class_cancelled','inapp','Class cancelled','{message}',NULL,NULL,NULL),
(NULL,'class_cancelled','push','Class cancelled','{message}',NULL,NULL,NULL),
(NULL,'holiday','whatsapp','Holiday','{message}','classnex_general','en','["message"]'),
(NULL,'holiday','inapp','Holiday: {title}','{message}',NULL,NULL,NULL),
(NULL,'holiday','push','Holiday: {title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','whatsapp','Announcement','{message}','classnex_general','en','["message"]'),
(NULL,'announcement','inapp','{title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','push','{title}','{message}',NULL,NULL,NULL),
(NULL,'announcement','email','{title}','{message}\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'announcement','sms','{title}','{message} -{academy_name}',NULL,NULL,NULL),
(NULL,'manual_message','whatsapp','Message','{message}','classnex_general','en','["message"]'),
(NULL,'manual_message','inapp','{title}','{message}',NULL,NULL,NULL),
(NULL,'manual_message','push','{title}','{message}',NULL,NULL,NULL),
(NULL,'manual_message','email','{title}','{message}\n\n{academy_name}',NULL,NULL,NULL),
(NULL,'manual_message','sms','{title}','{message} -{academy_name}',NULL,NULL,NULL),
(NULL,'account_invite','whatsapp','Your ClassNex account','{academy_name} has invited you to ClassNex. Activate your account: {link}','classnex_invite','en','["academy_name","link"]'),
(NULL,'account_invite','email','Activate your ClassNex account','Hello {recipient_name},\n\n{academy_name} has invited you to ClassNex.\nActivate your account here: {link}\n\nThis link is valid for 7 days.',NULL,NULL,NULL),
(NULL,'account_invite','sms','ClassNex invite','{academy_name} invited you to ClassNex. Activate: {link}',NULL,NULL,NULL),
(NULL,'password_reset','whatsapp','Password reset','Reset your ClassNex password: {link} (valid for 1 hour). Ignore if you did not request this.','classnex_password_reset','en','["link"]'),
(NULL,'password_reset','email','Reset your ClassNex password','Hello {recipient_name},\n\nReset your password using this link (valid for 1 hour):\n{link}\n\nIf you did not request this, ignore this email.',NULL,NULL,NULL),
(NULL,'password_reset','sms','Password reset','Reset your ClassNex password: {link}',NULL,NULL,NULL),
(NULL,'subscription_expiring','email','Your ClassNex plan expires on {date}','Dear {recipient_name},\n\nYour {package_name} plan for {academy_name} expires on {date}. Renew from your ClassNex dashboard to avoid interruption.\n\nTeam ClassNex',NULL,NULL,NULL),
(NULL,'subscription_expiring','inapp','Plan expires on {date}','Your {package_name} plan expires on {date}. Renew to avoid interruption.',NULL,NULL,NULL),
(NULL,'subscription_expiring','push','Plan expires on {date}','Your {package_name} plan expires on {date}.',NULL,NULL,NULL),
(NULL,'subscription_expiring','whatsapp','Plan expiring','Your ClassNex {package_name} plan for {academy_name} expires on {date}. Please renew from your dashboard.','classnex_subscription','en','["package_name","academy_name","date"]');

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('platform_name','ClassNex'),
('support_email','support@classnex.in'),
('support_phone',''),
('default_country_code','+91'),
('timezone','Asia/Kolkata'),
('currency_symbol','₹'),
('grace_days','7'),
('expired_mode','read_only'),
('invoice_advance_days','7'),
('queue_batch_size','50'),
('queue_paused_whatsapp','0'),
('queue_paused_push','0'),
('queue_paused_email','0'),
('queue_paused_sms','0'),
('attendance_notify_quiet_minutes','30'),
('document_retention_days','1095'),
('self_registration_enabled','1'),
('default_trial_package_id','2'),
('cron_last_run',''),
('ocr_provider','client'),
('google_vision_key_enc','');

INSERT IGNORE INTO website_settings (setting_key, setting_value) VALUES
('hero', '{"title":"Run your academy from one simple app","subtitle":"Students, attendance, fees, receipts and WhatsApp reminders — for cricket, dance, yoga, music, coaching and every kind of class.","cta_primary":"Start Free Trial","cta_secondary":"See How It Works","image":""}'),
('features', '[{"icon":"bi-person-plus","title":"30-second admissions","text":"Snap a photo, type a name and WhatsApp number — done. Scan a document to auto-fill the rest."},{"icon":"bi-calendar-check","title":"One-tap attendance","text":"Mark the whole batch present in one tap, fix the exceptions, and parents are informed automatically."},{"icon":"bi-cash-coin","title":"Fees that collect themselves","text":"Monthly, quarterly or one-time fees are billed automatically, with partial payments and advance credit handled for you."},{"icon":"bi-receipt","title":"Instant receipts","text":"Every payment creates a numbered PDF receipt you can print or share on WhatsApp in a tap."},{"icon":"bi-whatsapp","title":"Automatic WhatsApp reminders","text":"Official WhatsApp Business API reminders before and after due dates — no more awkward follow-up calls."},{"icon":"bi-phone","title":"Web + Android","text":"Owners, teachers, parents and students use one app on web or Android — always in sync."},{"icon":"bi-people","title":"Parent app","text":"Parents see attendance, fees and receipts for all their children, and switch between them instantly."},{"icon":"bi-graph-up","title":"Clear reports","text":"Collections, dues, attendance and admissions — filter and export to PDF, Excel or CSV."}]'),
('categories', '["Cricket","Football","Yoga","Karate","Martial Arts","Dance","Singing","Music","Swimming","Drawing & Art","Gym & Fitness","Coaching & Tuition","Languages","Other Activities"]'),
('steps', '[{"title":"Create your academy","text":"Register, add your activities and batches in minutes."},{"title":"Add students","text":"Quick registration with photo, WhatsApp number and batch."},{"title":"Mark attendance & collect fees","text":"From web or Android, by you or your teachers."},{"title":"Relax","text":"Receipts, reminders and parent updates go out automatically."}]'),
('stats', '[{"value":"30 sec","label":"to register a student"},{"value":"1 tap","label":"to mark a full batch"},{"value":"6","label":"automatic fee reminders"},{"value":"100%","label":"data isolation per academy"}]'),
('about', '{"title":"About ClassNex","body":"ClassNex is an all-in-one class management platform built in India for academies, institutes and activity centres of every kind. We believe running a class should be about teaching — not chasing fees on the phone or maintaining registers. ClassNex brings admissions, attendance, fee collection, receipts and parent communication into one simple app that anyone can use."}'),
('contact', '{"email":"support@classnex.in","phone":"","whatsapp":"","address":"India","map_embed":""}'),
('social', '{"facebook":"","instagram":"","youtube":"","linkedin":"","twitter":""}'),
('seo', '{"title":"ClassNex – All-in-One Class Management Software","description":"Manage students, attendance, fees, receipts and WhatsApp reminders for your academy, coaching or activity class. Web + Android.","keywords":"class management software, academy management, coaching institute software, fee management, attendance app, WhatsApp fee reminder"}'),
('footer', '{"text":"ClassNex – All-in-One Class Management. Simple software for academies, institutes and activity classes.","copyright":"ClassNex. All rights reserved."}'),
('banners', '[]'),
('faq', '[{"q":"Do my parents need to install an app?","a":"No. Parents get WhatsApp updates automatically. They can optionally use the ClassNex app or web portal to see attendance, fees and receipts."},{"q":"Is Aadhaar required?","a":"Never. Documents are optional, encrypted, and only visible to your academy."},{"q":"Can my teachers see fees?","a":"Not unless you allow it. Teachers only see their own batches by default."},{"q":"What happens if my plan expires?","a":"Your data is never deleted. After a short grace period the account becomes read-only until you renew."}]');

INSERT IGNORE INTO cms_pages (slug, title, content, meta_title, meta_description) VALUES
('privacy-policy','Privacy Policy','<p>This Privacy Policy explains how ClassNex ("we") collects, uses and protects information when academies, teachers, parents and students use the ClassNex website, web application and Android application.</p><h3>Information we process</h3><p>Academies enter student and parent details such as name, WhatsApp number, photograph and, optionally, address, date of birth and supporting documents. Each academy controls its own data; ClassNex processes it on the academy''s behalf.</p><h3>Children''s data</h3><p>For students under 18, academies must obtain verifiable consent from a parent or guardian. ClassNex records the consent and never uses children''s data for advertising or profiling.</p><h3>Identity documents</h3><p>Uploading any identity document is optional. Aadhaar is never mandatory. Documents are encrypted at rest, stored privately and are not publicly accessible. ClassNex never collects Aadhaar OTPs or biometric data, and does not store Aadhaar numbers extracted by OCR.</p><h3>Messaging</h3><p>Notifications are sent through the official WhatsApp Business Platform, email, SMS and push notifications. Recipients may opt out of WhatsApp messages by replying STOP.</p><h3>Retention and deletion</h3><p>Academies can delete documents at any time and request deletion of student records, subject to financial record-keeping obligations. Documents are automatically deleted after the configured retention period.</p><h3>Your rights</h3><p>Under the Digital Personal Data Protection Act, 2023 you may request access, correction or erasure of your personal data by contacting your academy or us at the support email.</p>','Privacy Policy – ClassNex','How ClassNex protects academy, student and parent data.'),
('terms','Terms and Conditions','<p>By registering for or using ClassNex you agree to these terms.</p><h3>Accounts</h3><p>The academy owner is responsible for all users added to the academy and for keeping login credentials secure.</p><h3>Subscriptions</h3><p>Plans are billed per the selected billing cycle. When a subscription expires, a grace period applies, after which the account becomes read-only until renewal. Data is never deleted because a plan expires.</p><h3>Acceptable use</h3><p>You must have the right to process the personal data you enter, obtain parental consent for minors, and send only messages your recipients have agreed to receive.</p><h3>Payments</h3><p>Fee payments recorded in ClassNex are records of the academy. Online payments are processed by the academy''s configured payment gateway.</p><h3>Liability</h3><p>ClassNex is provided on an "as is" basis. Our liability is limited to the subscription fees paid in the preceding three months.</p>','Terms and Conditions – ClassNex','Terms of use for the ClassNex platform.'),
('refund-policy','Refund Policy','<p>Subscription fees are payable in advance. If you cancel within 7 days of your first paid subscription, contact support for a full refund. Renewals are non-refundable but you may cancel future renewals at any time.</p>','Refund Policy – ClassNex','ClassNex subscription refund policy.');

INSERT IGNORE INTO testimonials (name, designation, message, rating, sort_order) VALUES
('Academy Owner','Cricket Academy','Fee follow-ups used to take hours every month. Now reminders go out on WhatsApp automatically and parents pay on time.',5,1),
('Studio Director','Dance Studio','Registering a new student takes less than a minute, even with the photo. Our teachers mark attendance from their phones.',5,2),
('Coaching Centre Owner','Tuition Classes','Parents love getting the receipt on WhatsApp right after paying. Very simple to use.',5,3);

INSERT IGNORE INTO whatsapp_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO firebase_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO email_configurations (id, mode) VALUES (1,'test');
INSERT IGNORE INTO sms_configurations (id, mode) VALUES (1,'off');
