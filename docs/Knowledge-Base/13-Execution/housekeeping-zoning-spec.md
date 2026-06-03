# Housekeeping Zoning Specification

Project: IVORQ Hotel Operations Platform

Module: Housekeeping

Version: 1.0

Status: Approved

Owner: CTO

---

# Overview

Housekeeping Zoning adalah sistem pembagian area kerja operasional berdasarkan lokasi fisik property.

Zoning digunakan oleh:

* Housekeeping
* Engineering
* Security
* Guest Request
* Public Area Team

---

# Objectives

* Mengurangi waktu perjalanan staff
* Mempermudah task assignment
* Meningkatkan produktivitas
* Mempermudah monitoring
* Mempermudah reporting
* Mendukung resort operation

---

# Resort Structure

Property

↓

Zone

↓

Villa / Building

↓

Room

↓

Task

---

# Zone Types

## Guest Accommodation

* Villas
* Rooms
* Suites

## Public Areas

* Lobby
* Reception
* Walkways
* Garden

## Food & Beverage

* Restaurant
* Bar
* Kitchen

## Recreation

* Swimming Pool
* Gym
* Spa

## Back Of House

* Staff Area
* Laundry
* Engineering Workshop

## Custom Zones

Property specific areas.

---

# Example

Uluwatu Surf Villas

Zone A

Upper Side Villas

---

Zone B

Lower Side Villas

---

Zone C

Pool Area

---

Zone D

Restaurant Area

---

Zone E

Public Area

---

Zone F

Staff Area

---

Zone G

Engineering Area

---

# Zone Master Fields

zone_code

zone_name

description

property_id

status

priority

created_by

updated_by

---

# Database Tables

## housekeeping_zones

id

property_id

zone_code

zone_name

description

status

priority

created_at

updated_at

---

## zone_assignments

id

zone_id

employee_id

department_id

start_date

end_date

status

---

## zone_histories

id

zone_id

action

performed_by

remarks

created_at

---

# Zone Lifecycle

Draft

↓

Active

↓

Suspended

↓

Archived

---

# Assignment Rules

One employee may be assigned to multiple zones.

One zone may contain multiple employees.

Assignments must be date controlled.

Assignments must be auditable.

---

# Housekeeping Integration

Room

↓

Zone

↓

Cleaning Task

↓

Assignment

↓

Completion

---

# Engineering Integration

Work Order

↓

Zone

↓

Technician Assignment

↓

Completion

---

# Security Integration

Incident

↓

Zone

↓

Security Assignment

↓

Resolution

---

# Guest Request Integration

Guest Request

↓

Room

↓

Zone

↓

Department Routing

↓

Assignment

---

# Dashboard Requirements

## Operational Dashboard

Tasks By Zone

Rooms By Zone

Open Requests By Zone

Open Work Orders By Zone

---

## Management Dashboard

Zone Productivity

Zone Completion Rate

Zone Workload

Zone SLA Performance

---

# Reporting

Zone Productivity Report

Zone Performance Report

Zone Assignment Report

Zone Workload Report

Zone SLA Report

---

# Notifications

Triggers:

Zone Assignment

Zone Reassignment

Zone Suspension

Zone Reactivation

---

# Future Features

AI Workforce Distribution

AI Route Optimization

Heat Map Analytics

Mobile Zone Navigation

Indoor Mapping

---

# Security Requirements

Authentication Required

RBAC Required

Property Isolation Required

Audit Logging Required

Activity Logging Required

---

# Testing Requirements

Zone CRUD

Zone Assignment

Zone Routing

Zone Reporting

Property Isolation

Permission Validation

---

# Definition Of Done

✓ Zone Master

✓ Zone Assignment

✓ Dashboard

✓ Reporting

✓ Security

✓ Audit Logging

✓ Property Scope

✓ Integration Ready

---

# CTO Recommendation

All operational modules must use the same zoning engine.

Never create department-specific zoning systems.

One zoning framework must serve:

* Housekeeping
* Engineering
* Security
* Guest Request

This reduces complexity and improves maintainability.

---

# Final Rule

Zoning is the official location framework for IVORQ operations.

All future operational modules must integrate with this specification.
