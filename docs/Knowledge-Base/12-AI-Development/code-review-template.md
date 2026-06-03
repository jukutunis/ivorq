
# Code Review Template

Project: IVORQ Hotel Operations Platform

Version: 1.0

Status: Approved

Owner: CTO

---

# Purpose

Template ini digunakan untuk seluruh code review sebelum merge ke main branch.

Seluruh AI Assistant dan Developer wajib mengikuti template ini.

---

# Review Information

Review ID:

Date:

Reviewer:

Developer:

Module:

Feature:

Branch:

Pull Request:

---

# Review Scope

Type:

Feature

Bug Fix

Refactor

Optimization

Security Fix

Infrastructure

Documentation

---

# Architecture Review

Check:

✓ Follows Architecture Rules

✓ Follows DDD Principles

✓ Follows Module Boundaries

✓ No Forbidden Dependencies

✓ Uses Approved Patterns

Comments:

---

# Database Review

Check:

✓ Follows Database Standards

✓ Uses ULID

✓ Uses Property Scope

✓ Indexes Added

✓ Foreign Keys Correct

✓ No Data Integrity Risks

Comments:

---

# Repository Review

Check:

✓ Repository Pattern Used

✓ Queries Centralized

✓ No Business Logic In Controllers

✓ No Duplicate Queries

Comments:

---

# Service Layer Review

Check:

✓ Business Logic In Services

✓ Single Responsibility

✓ Reusable Components

✓ Proper Exception Handling

Comments:

---

# Controller Review

Check:

✓ Thin Controllers

✓ Validation Applied

✓ Authorization Applied

✓ Resource Responses Used

Comments:

---

# Security Review

Check:

✓ Authentication Verified

✓ Authorization Verified

✓ Property Scope Verified

✓ No Privilege Escalation

✓ No Sensitive Data Exposure

✓ Audit Logging Implemented

Comments:

---

# API Review

Check:

✓ REST Standards

✓ Naming Consistency

✓ Validation Rules

✓ Error Handling

✓ Pagination Support

✓ Documentation Updated

Comments:

---

# Frontend Review

Check:

✓ UI Guidelines Followed

✓ Reusable Components

✓ Proper State Management

✓ Loading States

✓ Error Handling

✓ Accessibility Considered

Comments:

---

# Performance Review

Check:

✓ No N+1 Queries

✓ Database Indexed

✓ Efficient Queries

✓ Queue Usage Considered

✓ Caching Considered

Comments:

---

# Event Review

Check:

✓ Events Used Correctly

✓ Listeners Registered

✓ Jobs Queued Properly

✓ No Tight Coupling

Comments:

---

# Testing Review

Check:

✓ Unit Tests Added

✓ Feature Tests Added

✓ Integration Tests Added

✓ Authorization Tests Added

✓ Property Isolation Tested

Comments:

---

# Documentation Review

Check:

✓ PRD Updated

✓ API Documentation Updated

✓ Database Documentation Updated

✓ Release Notes Updated

Comments:

---

# Laravel Standards Review

Check:

✓ PSR Standards

✓ Laravel Best Practices

✓ Naming Conventions

✓ Folder Structure Compliance

✓ Type Hinting Used

✓ Return Types Defined

Comments:

---

# Risk Assessment

Risk Level:

Low

Medium

High

Critical

Reason:

Mitigation:

---

# Findings

Critical Issues:

High Priority Issues:

Medium Priority Issues:

Low Priority Issues:

Recommendations:

---

# Review Decision

Approved

Approved With Changes

Changes Required

Rejected

---

# Approval Checklist

✓ Architecture Approved

✓ Security Approved

✓ Database Approved

✓ Testing Approved

✓ Documentation Approved

✓ CTO Approved

---

# AI Instructions

Before approving code:

1. Review Architecture
2. Review Database
3. Review Security
4. Review Performance
5. Review Tests
6. Review Documentation
7. Review Risks
8. Produce Findings
9. Produce Decision

Never approve code without reviewing security.

Never approve code without tests.

Never approve code with unresolved critical issues.

---

# Final Rule

No code may be merged into the main branch without passing this review process.
