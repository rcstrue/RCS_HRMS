import { NextRequest, NextResponse } from 'next/server'
import { db } from '@/lib/db'

// GET /api/contractors — list with search, pagination
export async function GET(req: NextRequest) {
  try {
    const url = req.nextUrl
    const search = url.searchParams.get('search')?.trim() || ''
    const page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10))
    const limit = Math.min(100, Math.max(1, parseInt(url.searchParams.get('limit') || '20', 10)))
    const sortBy = url.searchParams.get('sortBy') || 'createdAt'
    const sortOrder = url.searchParams.get('sortOrder') || 'desc'

    const where: Record<string, unknown> = {}
    if (search) {
      where.OR = [
        { contractorName: { contains: search } },
        { registrationNumber: { contains: search } },
        { licenseNumber: { contains: search } },
        { establishmentName: { contains: search } },
        { natureOfWork: { contains: search } },
        { contactPersonName: { contains: search } },
        { contactPersonMobile: { contains: search } },
      ]
    }

    const [records, total] = await Promise.all([
      db.labourContractor.findMany({
        where,
        orderBy: { [sortBy]: sortOrder === 'asc' ? 'asc' : 'desc' },
        skip: (page - 1) * limit,
        take: limit,
      }),
      db.labourContractor.count({ where }),
    ])

    return NextResponse.json({
      records,
      pagination: {
        page,
        limit,
        total,
        totalPages: Math.ceil(total / limit),
      },
    })
  } catch (error) {
    console.error('GET /api/contractors error:', error)
    return NextResponse.json({ error: 'Failed to fetch contractors' }, { status: 500 })
  }
}

// POST /api/contractors — create
export async function POST(req: NextRequest) {
  try {
    const body = await req.json()

    // Convert date strings to Date objects
    const dateFields = [
      'dateOfRegistration', 'licenseIssueDate', 'licenseValidFrom',
      'licenseValidTo', 'contractStartDate', 'contractEndDate',
    ]
    for (const field of dateFields) {
      if (body[field] && typeof body[field] === 'string' && body[field] !== '') {
        body[field] = new Date(body[field])
      } else if (body[field] === '' || body[field] === null || body[field] === undefined) {
        body[field] = null
      }
    }

    // Convert numeric strings to numbers
    const numericFields = ['maxWorkmen', 'licenseFee', 'contractValue', 'securityDeposit']
    for (const field of numericFields) {
      if (body[field] !== undefined && body[field] !== null && body[field] !== '') {
        body[field] = parseFloat(String(body[field])) || 0
      }
    }

    // String fields - ensure no undefined
    const stringFields = [
      'contractorName', 'contractorAddress', 'registrationNumber', 'contractorPan',
      'establishmentName', 'establishmentAddress', 'natureOfWork', 'licensingAuthority',
      'licenseNumber', 'contractorGstin', 'pfRegistrationNumber',
      'contactPersonName', 'contactPersonDesignation', 'contactPersonMobile', 'contactPersonEmail',
      'bankName', 'bankAccountNumber', 'bankIfscCode', 'esiRegistrationNumber',
      'contractWorkLocation', 'remarks',
    ]
    for (const field of stringFields) {
      if (body[field] === undefined || body[field] === null) {
        body[field] = ''
      }
    }

    if (!body.contractorName || String(body.contractorName).trim() === '') {
      return NextResponse.json({ error: 'Contractor name is required' }, { status: 400 })
    }

    const record = await db.labourContractor.create({ data: body })
    return NextResponse.json(record, { status: 201 })
  } catch (error) {
    console.error('POST /api/contractors error:', error)
    return NextResponse.json({ error: 'Failed to create contractor' }, { status: 500 })
  }
}
