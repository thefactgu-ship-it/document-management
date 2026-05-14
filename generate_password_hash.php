<?php
// generate_password_hash.php
$new_password = '123'; // เปลี่ยนตรงนี้เป็นรหัสผ่านที่คุณต้องการใช้จริงๆ
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
echo "รหัสผ่านใหม่ที่แฮชแล้วคือ: " . $hashed_password;
?>