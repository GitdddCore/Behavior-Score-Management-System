# Behavior Score Management System (BSMS)

>**Sorry, this project has not been translated into English yet**

>**中文版本: [点击这里](./README.md)**

## Project Overview

The Behavior Score Management System (BSMS) is a web application that provides teachers with convenient behavior score management.

### What can it do?

- For Students:
  - View class rankings
  - View detailed behavior score records
  - Appeal unreasonable behavior score records
  ---
- For Class Administrators (Class Committee):
  - Modify behavior scores for all students in the class (with operation tracking)
  - View behavior score records for all students in the class (cannot delete)
  ---
- For System Administrators (usually homeroom teachers):
  - Data statistics and records page (dashboard)
  - Student management: including adding, deleting, querying, and modifying student information, semester reset, batch modification/addition
  - Class administrator management: allows selection of class administrators from the class student group
  - Rule management: can set behavior score rules for the class
  - Behavior score management: can delete behavior score records, modify behavior scores for all students in the class (with operation tracking)
  - Appeal management: can review appeals submitted by students
  - Data export: can quickly export behavior records, student scores, and appeal information as EXCEL files

### Project Screenshots:

![AAA](under testing)

## How to Deploy

### System Requirements:

- PHP Version: 7.4 or higher
- Database Version: MySQL 5.7 or higher
- Web Server: Apache or Nginx

### Installation Steps:

1. Download the latest release package of the project
2. Extract the package to your web server's root directory
3. Run `"0 - System Templates/Password Hash Tools.exe"` to hash encrypt your administrator password
4. Copy the password hash value and replace the default administrator account password column in `"0 - System Templates/SQL Template.sql"`
5. Import the database template file `"0 - System Templates/SQL Template.sql"` into your MySQL database
6. Configure `"config/config.json"`, fill in MySQL database connection information, Redis database connection information, class name, teacher name, and default score
7. Run `composer install` command to download and install dependencies in composer.json
8. Start the service
9. Complete

### Important Notes

1. Please ensure Redis database reserves `0, 1, 2` databases. If they cannot be used for special reasons, please configure the Redis database connection information in `"config/config.json"`
```
      "databases": {
        "0": "session",             // Remember me function
        "1": "login_security",      // Login attempt function
        "2": "cache"                // Cache function

        // Please modify "0", "1", "2" database numbers to available databases!
      }
```
2. This project defaults to an initial behavior score of 100 points. If you need to modify it, please configure the `initial_score` value in `"config/config.json"`, score range: 0~100

## Quick Start

**Congratulations on successful installation! Please read this section to quickly understand how to use this system**

### How to configure students, class administrators, rules, and other functions?

> **All operations require administrator account login! Please use the administrator account (default is admin for newly installed systems, if you modified the default administrator username in the SQL template, please use your modified username) to log into the system!**

#### Configure Students:

##### About Single Addition
1. Click `Student Management` in the navigation bar
2. Click Add Student
3. Fill in student information and click Save
4. Complete

##### About Batch Addition
1. Click `Student Management` in the navigation bar
2. Click Batch Add
3. Choose text input or file upload
 - Input format: Name StudentID BehaviorScore StudentStatus(optional) AppealPermission(optional), separated by spaces. Student status and appeal permission are enabled with 1, disabled with 0, default is enabled
4. Fill in student information and click Batch Add
5. Complete

#### Student Status Settings:

> **Student status is only for situations like temporary suspension. After disabling a student, the student's class administrator status will be suspended, and the student's behavior score can no longer be modified, but they can still view current rankings and use appeal functions; appeal permission is for malicious appeals - after disabling, students will not be allowed to appeal**

1. Click `Student Management` in the navigation bar
2. Click the `Edit` button for the target student
3. Modify student information and click Save
4. Complete

---

#### Configure Class Administrators:
1. Click `Class Administrator Management` in the navigation bar
2. Click Add Class Administrator
3. Select student (only supports added students), select position, fill in tenure time, fill in class administrator's login password (optional, default is 123456), click Save
4. Complete

*Note: If you forget the password, you can click the edit button in Class Administrator Management to reset the password*

---

#### Configure Rules:

##### About Single Addition:
1. Click `Rule Management` in the navigation bar
2. Click Add Rule
3. Fill in rule name, fill in rule content, click Save
4. Complete

##### About Batch Addition:
1. Click `Rule Management` in the navigation bar
2. Click Batch Add
3. Choose text input or file upload
 - Input format: RuleName RuleDescription Score Type(reward/penalty) separated by spaces. When type is reward, negative scores are not allowed; when penalty, positive scores are not allowed
4. Fill in rule information and click Batch Add
5. Complete

---

**After performing the above operations, the system will work normally. Congratulations!**

## Frequently Asked Questions

Q1: How do class administrators log in?

Solution: When class administrators log in, fill in the class administrator's student ID as username and the class administrator's set login password as password (default is 123456)

Q2: How do administrators change passwords?

Solution: If administrators forget their password, they need to reset it by editing the database. The system currently does not provide UI reset functionality. Please use Password Hash Tools.exe to generate a new hash value and store it in the database

Q3: A json file was exported during export?

Solution: This may be caused by missing dependency files. You need to re-run the `composer install` command to download and install dependencies in composer.json

(Waiting for issues to continue writing...)

## Update Log

### 1.0.0 Update
- Initial version release

## Declaration

**This project is open source under the MIT License, allowing anyone to freely use, modify, and distribute this software**

### Disclaimer

**This software is provided "as is", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and noninfringement. In no event shall the authors or copyright holders be liable for any claim, damages or other liability, whether in an action of contract, tort or otherwise, arising from, out of or in connection with the software or the use or other dealings in the software.**

### Contribution Guidelines
**We welcome contributions of any kind! If you find bugs in the project or have suggestions for improvement, please submit an issue or Pull Request. Thank you for your attention and support for this project!**