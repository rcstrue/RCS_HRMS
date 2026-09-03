import { NextRequest, NextResponse } from 'next/server'
import { db } from '@/lib/db'

// GET /api/contractors/[id]
export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params
    const record = await db.labourContractor.findUnique({ where: { id } })
    if (!record) {
      return NextResponse.json({ error: 'Contractor not found' }, { status: 404 })
    }
    return NextResponse.json(record)
  } catch (error) {
    console.error('GET /api/contractors/[id] error:', error)
    return NextResponse.json({ error: 'Failed to fetch contractor' }, { status: 500 })
  }
}

// PUT /api/contractors/[id]
export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params
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

    // Ensure no undefined string fields
    const stringFields = [
      'contractorName', 'contractorAddress', 'registrationNumber', 'contractorPan',
      'establishmentName', 'establishmentAddress', 'natureOfWork', 'licensingAuthority',
      'licenseNumber', 'contractorGstin', 'pfRegistrationNumber',
      'contactPersonName', 'contactPersonDesignation', 'contactPersonMobile', 'contactPersonEmail',
      'bankName', 'bankAccountNumber', 'bankIfscCode', 'esiRegistrationNumber',
      'contractWorkLocation', 'remarks',
    ]
    for (const field of stringFields) {
      if (body[field] !== undefined && (body[field] === null || body[field] === undefined)) {
        body[field] = ''
      }
    }

    if (body.contractorName !== undefined && String(body.contractorName).trim() === '') {
      return NextResponse.json({ error: 'Contractor name is required' }, { status: 400 })
    }

    // Remove id from body to avoid conflicts
    delete body.id
    delete body.createdAt
    delete body.updatedAt

    const record = await db.labourContractor.update({
      where: { id },
      data: body,
    })
    return NextResponse.json(record)
  } catch (error) {
    console.error('PUT /api/contractors/[id] error:', error)
    return NextResponse.json({ error: 'Failed to update contractor' }, { status: 500 })
  }
}

// DELETE /api/contractors/[id]
export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params
    await db.labourContractor.delete({ where: { id } })
    return NextResponse.json({ success: true })
  } catch (error) {
    console.error('DELETE /api/contractors/[id] error:', error)
    return NextResponse.json({ error: 'Failed to delete contractor' }, { status: 500 })
  }
}
