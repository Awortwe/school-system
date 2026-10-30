# Staff operating guide

## All staff

Sign in with a staff account. Change an initial password when prompted. Navigation reflects your permissions, and the server checks permissions and record assignments again. Use Sign out when finished. Password recovery requires the school administrator to configure mail delivery. Students and guardians are records, never login accounts.

## Owner / Super Administrator

Create staff accounts under Staff accounts; provide a temporary password and require a change. Assign one of the five staff roles and, when needed, explicit additional permissions. Only a Super Administrator can assign Super Administrator access or direct permission overrides. Retain at least one active owner account.

Review branding/contact details, term dates, assessment configurations, fees, approved signatures and report templates before official use. School settings also configure payment methods, attendance sessions, ranking/rounding options and thermal width. Templates and scored result configurations are versioned; published reports retain their original content.

Payroll approval, salary access, transport management, inventory management, purchase approval and asset disposal are separate permissions. Grant the minimum needed for each staff member. Salary details are also protected from indirect access through ordinary expense/cashbook/audit screens.

## Headteacher / Academic Administrator

Use Academic setup to configure years, terms, levels, classes, sections, subjects and teaching assignments. Set the intended year/term as current. Assign class teachers and subject teachers before academic work starts.

Review completed result sheets, approve them to lock scores, then publish a class. Missing required scores prevent submission. Reopening requires a reason and is audited. Published report versions remain unchanged. Use Published report cards to preview/download individual PDFs or generate a class PDF. Large class requests appear under Class PDF jobs and require a running queue worker.

Use Learning progress for Montessori observations and class/headteacher comments. Published narrative/report snapshots are locked. Record reviewed progression to a later academic year with the decision reason; history and balances remain. Optional versioned promotion rules check published average, subject pass percentage and core failures. An academic approver must explicitly select an override and supply a justification when the published results do not meet a rule. The selected source term and target placement should be reviewed carefully before applying progression.

## Administrative staff

Register an application in Admissions, review its decision, approve it and place the approved applicant in a section. Admission conversion creates the student, guardian and enrolment once. Alternatively, Add student creates a direct student record.

Student profiles allow authorised editing, private photographs, guardian/emergency details and links to existing guardians. Sharing a guardian links siblings. Class changes retain enrolment history. Archiving retains academic/financial records.

Use Student imports with the downloadable CSV template or an XLSX workbook's first sheet. The worker validates each row; valid rows are saved and invalid rows appear in the error summary. Never import unreviewed files into production. Upload student records through Private documents, and vehicle/purchasing/asset documents through Operational supporting documents.

Administration contains calendar events, staff announcements, staff attendance and staff-maintained parent communication records. An announcement without a publication date remains a draft.

## Teachers / Class teachers

Access only assigned sections and subjects. Choose a class/date/session in Attendance, mark each learner and save. Review term summaries through Attendance summaries.

Use Results to open an assigned subject sheet. Enter the component scores and comments; blanks stay missing, not zero. Maximum-score checks run on the server. Save, review totals and submit when complete. Approved results cannot be edited until an authorised administrator reopens them.

Class teachers can record authorised class observations/conduct and access class reports. Headteacher comments and official publication require academic approval permissions. Daily assignments and relevant timetable information appear on the dashboard.

## Accountant / Bursar

Configure fee categories/items and class/term/category fee structures. Generate an individual or class invoice. Transport fees require approval before they are added to the same invoice and student ledger. Prior confirmed unallocated credit is applied to new invoices.

Record confirmed payments with the date, method, reference and optional private proof. Allocations may be entered for selected invoices; blanks apply the payment to oldest outstanding invoices first. Overpayments remain credit. A receipt retains the confirmation-time details; later reprints are copies. Reversed payments remain visible and their receipts show REVERSED.

Use Statements for cross-term balances/arrears/credit. Request discounts, scholarships or adjustments through the separate approval workflow. Reversals and refunds require their own permissions and reasons. Enter expenses for approval and income with the appropriate categories. Reconcile cashbook entries only after checking supporting evidence. Operational reports are not a general ledger.

## Payroll staff and approvers

The owner must grant payroll and salary permissions explicitly. Create a verified effective statutory profile, then create effective-dated salary profiles with confirmed residency/pension coverage and approved payment details. Payment details are encrypted and have a separate audited view.

Recurring components apply within effective dates; a selected payroll period makes a component one-off. Allowances/bonuses, deductions, advances/loan repayments and employer costs are separate component types. Deductions entered here are after tax; approved tax relief belongs in the salary profile. Review special bonuses, overtime, benefits in kind, proration and unusual classifications with the accountant.

Create a full calendar-month period, calculate draft entries, submit for review and approve/lock. Record actual partial/full salary payments, employee statutory remittances and employer contributions separately. Only actual payments create linked approved expenses/cashbook entries. Do not enter the same payment again as a manual expense. Use documented authorised corrections instead of changing approved entries. Individual and whole-period payslips are available after approval.

## Transport staff

Register vehicles, capacities, document-expiry dates and drivers/licences. Define route stops, weekdays and departure/finish times. Assign a vehicle/driver with valid dates; overlapping schedules and insufficient capacity are rejected. Enrol learners at configured stops within the assignment's dates. Close an enrolment with an end date/reason before changing arrangements.

Create draft route/category/term fees; a fee manager approves them before billing. Approved fees join normal student invoices and statements. Record fuel, services, repairs or breakdowns with costs and next-due dates. Costs create one normal expense request, not a separate transport cashbook. Review expiry reminders and upload supporting documents. No GPS is included.

## Inventory and asset staff

Create consumable/asset categories separately, storage locations, suppliers and consumable item codes/units/thresholds. Add opening quantities using movements. Issues/damage subtract; receipts/returns add; signed adjustments require a reason. Stock cannot become negative. Movement history is retained.

Request purchases, obtain separate approval, record the purchase order and confirm full goods receipt. Receipt adds stock and creates one linked expense request. Finance approves the expense. Do not manually duplicate that expense. Track low-stock alerts and export stock/movement/purchasing reports.

Register capital assets separately with original purchase cost/date, location, condition and assignee/department. Record transfers, repairs, maintenance or authorised disposal as events. No depreciation policy is assumed. Upload purchase/asset evidence through Operational supporting documents.

## Printing

Use dedicated PDF links for A4 reports, invoices, receipts, statements and payslips. For receipts, select 58 mm or 80 mm thermal preview, choose the matching OS printer, scale 100%, disable browser headers/footers and remove printer margins where supported. Content uses its natural height. The application does not directly control USB, Bluetooth or network printers. Test the actual device before regular use.
