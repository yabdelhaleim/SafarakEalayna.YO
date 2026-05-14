<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case CANCELLED = 'cancelled';
}

enum TransactionType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
}

enum PaymentMethod: string
{
    case CASH = 'cash';
    case CASH_EGP = 'cash_egp';
    case BANK_TRANSFER = 'bank_transfer';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case MOBILE_MONEY = 'mobile_money';
    case POST_OFFICE = 'post_office';
}

enum FinanceStatus: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}

enum ProgramType: string
{
    case UMRA = 'UMRA';
    case HAJJ = 'HAJJ';
}

enum VisaType: string
{
    case TOURIST = 'TOURIST';
    case BUSINESS = 'BUSINESS';
    case VISIT = 'VISIT';
    case TRANSIT = 'TRANSIT';
    case WORK = 'WORK';
    case STUDENT = 'STUDENT';
    case UMRA = 'UMRA';
    case HAJJ = 'HAJJ';
    case RESIDENCE = 'RESIDENCE';
}

enum VisaStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
}

enum BookingStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case WAITLIST = 'WAITLIST';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';
}

enum AccommodationType: string
{
    case SINGLE = 'SINGLE';
    case DOUBLE = 'DOUBLE';
    case TRIPLE = 'TRIPLE';
    case QUAD = 'QUAD';
}

enum BookingVStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case REFUNDED = 'REFUNDED';
    case CANCELLED = 'CANCELLED';
}

enum TransactionType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
}

enum PaymentMethod: string
{
    case CASH = 'cash';
    case CASH_EGP = 'cash_egp';
    case BANK_TRANSFER = 'bank_transfer';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case MOBILE_MONEY = 'mobile_money';
    case POST_OFFICE = 'post_office';
}

enum FinanceStatus: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
