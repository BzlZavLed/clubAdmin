# Product Backlog Overview

This document captures the current high-level features still to be built or refined.

It is intentionally broad. Detailed requirements, UI flows, database changes, and permissions can be refined later.

## 1. Parent payments and payment proof workflow

### Goal
Allow parents to see what payments are expected for their children and submit proof when payment is made outside the platform, especially bank transfers.

### Needed capabilities
- Show expected payments to parents:
  - monthly dues / cuotas
  - insurance
  - enrollment or registration fees
  - other club-defined charges
- Show payment status for each expected payment:
  - pending
  - partially paid
  - paid
  - proof submitted
  - under review
  - approved
  - rejected
- Allow parent to submit payment proof:
  - image upload
  - PDF upload
  - optional transfer reference / confirmation number
  - optional notes
  - submission date
- Allow club to review parent-submitted proof:
  - approve
  - reject
  - request correction / resubmission
- Preserve audit trail:
  - who submitted
  - who reviewed
  - timestamps
  - status history

### Likely actors
- Parent
- Club director
- Club finance staff

### Open questions
- Can one proof apply to multiple children or only one child at a time?
- Can one proof apply to multiple expected charges?
- Do clubs define their own charge catalog, or do some charges come from higher levels?
- Should approved transfer proofs create an actual payment automatically, or stay separate until manually posted?

## 2. District visibility into clubs and members

### Goal
District should be able to view every club in its district and all members of those clubs.

### Needed capabilities
- District list of clubs
- Club detail from district level
- Member list per club
- Filters:
  - club
  - class
  - active / inactive
  - payment status
  - insurance status
  - investiture progress
- Summary totals by club and by district

### Permissions expectation
- District can view data across clubs in its district
- District should not edit club-level financial records unless explicitly allowed later

## 3. Association visibility into districts, clubs, and members

### Goal
Association should be able to see all districts under it, all clubs in those districts, and related member totals / detail.

### Needed capabilities
- District list under association
- Club list under each district
- Member totals and drill-down views
- Summary reporting across the whole association
- Search and filter across all subordinate entities

### Permissions expectation
- Association has broad visibility
- Edit permissions should be defined separately from reporting permissions

## 4. Association insurance receivables from clubs

### Goal
Association needs to track how much insurance money each club is expected to remit after collecting from members.

### Business concept
- Club charges insurance to members
- Club receives insurance money
- Club now owes that insurance amount to association
- Association must track expected amount, collected amount, pending amount, and remittance status by club

### Needed capabilities
- Insurance expected amount per member
- Insurance collection totals at club level
- Amount owed by club to association
- Association view of all clubs and outstanding insurance remittances
- Remittance workflow:
  - pending
  - partially remitted
  - remitted
  - verified
- Evidence / proof for club-to-association remittance
- Reconciliation between:
  - members charged
  - members paid
  - club collected amount
  - club remitted amount
  - association verified amount

### Open questions
- Is insurance amount globally defined by association, or can it vary by club / year?
- Should remittances be tracked by period (month, quarter, year, event)?
- Can a club remit a bulk payment covering multiple members?

## 5. SDA church membership and evangelistic follow-up tracker

### Goal
Each club should track whether a member belongs to the SDA church, and if not, track evangelistic follow-up progress.

### Needed capabilities at club level
- Member field: is SDA church member?
- If not SDA:
  - who is working with the person
  - related church
  - follow-up status
  - notes
  - Bible study / indoctrination tracking
  - baptism status
  - baptism date
- Ability to update progress over time

### Reporting and visibility
- Club can maintain full detail
- District can view detailed records across clubs in district
- Association can view detailed records across districts
- Union should receive reports / totals, not necessarily full personal detail

### Reporting expectations
- Totals by church
- Totals by club
- Totals by district
- Totals by association
- Who is working with each non-SDA member
- Baptism progress and completed baptisms

### Open questions
- Is this tracked only for members, or also for parents / contacts?
- Should follow-up "who is working with them" support multiple people?
- Should there be a formal status pipeline, for example:
  - identified
  - contacted
  - studying
  - attending
  - preparing for baptism
  - baptized

## 6. Cross-cutting concerns

These features will likely need shared platform work.

### Permissions / scopes
- Club
- District
- Association
- Union
- Parent

Each feature should define:
- who can view
- who can create
- who can approve
- who can export

### Reporting
- Totals
- Drill-down views
- Filters by hierarchy
- Exportable CSV / PDF where needed

### Evidence / attachments
- Payment proof uploads
- Remittance proof uploads
- Possibly follow-up related documents

Common needs:
- secure storage
- file type validation
- upload size limits
- audit trail

### Auditability
Important for finance and evangelism tracking:
- status history
- reviewer / approver identity
- timestamps
- notes

## 7. Suggested implementation phases

### Phase 1
- Parent expected payments view
- Parent payment proof submission
- Club review of payment proof

### Phase 2
- District visibility into clubs and members
- Association visibility into districts and clubs

### Phase 3
- Association insurance receivables and remittance tracking

### Phase 4
- Evangelistic tracker for non-SDA members
- District / association detail views
- Union aggregate reporting

## 8. Immediate refinement topics

Before implementation, these items should be clarified:

1. Payment model
- expected charge structure
- approval workflow for transfer proofs
- whether proof creates real payments automatically

2. Hierarchy permissions
- exact read vs write permissions for district / association / union

3. Insurance remittance model
- how expected amounts are calculated
- how remittances are grouped and verified

4. Evangelistic tracker model
- required fields
- status pipeline
- privacy level for each hierarchy level

