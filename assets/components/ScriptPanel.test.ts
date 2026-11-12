import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { ScriptPreview, ScriptRun } from '../api/script'
import ScriptPanel from './ScriptPanel.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

const preview = (over: Partial<ScriptPreview> = {}): ScriptPreview => ({
  steps: [
    { index: 0, sql: 'INSERT INTO t VALUES (1)', verdict: 'allowed', kind: 'write', reason: null },
    { index: 1, sql: 'DELETE FROM t', verdict: 'needs_confirmation', kind: 'write', reason: 'destructive_without_where' },
  ],
  canRun: true,
  needsConfirmation: true,
  destructiveCount: 1,
  firstRefused: null,
  transactional: true,
  atomic: true,
  dryRun: { available: true, reason: null },
  warnings: [],
  ...over,
})

const run = (over: Partial<ScriptRun> = {}): ScriptRun => ({
  executionId: 'e1',
  status: 'committed',
  dryRun: false,
  failureReason: null,
  applied: [0, 1],
  failed: null,
  notRun: [],
  steps: [
    { index: 0, sql: 'INSERT INTO t VALUES (1)', status: 'applied', affectedRows: 1, returnedRows: null, error: null, durationMs: 1 },
    { index: 1, sql: 'DELETE FROM t', status: 'applied', affectedRows: 1, returnedRows: null, error: null, durationMs: 1 },
  ],
  ...over,
})

function mountPanel(script = 'INSERT INTO t VALUES (1); DELETE FROM t') {
  return mount(ScriptPanel, { props: { connectionId: 'abc', script, scope: { database: null, schema: null } }, attachTo: document.body })
}

async function previewed(over: Partial<ScriptPreview> = {}, script?: string) {
  const wrapper = mountPanel(script)
  mocked.mockResolvedValueOnce(preview(over))
  await wrapper.get('button.preview').trigger('click')
  await flushPromises()

  return wrapper
}

describe('ScriptPanel', () => {
  beforeEach(() => mocked.mockReset())

  it('cannot run anything before the script has been previewed', () => {
    const wrapper = mountPanel()

    expect(wrapper.get('button.execute').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('shows the verdict of every statement from the preview, without executing', async () => {
    const wrapper = await previewed()

    expect(mocked).toHaveBeenCalledTimes(1)
    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/script/preview', { script: 'INSERT INTO t VALUES (1); DELETE FROM t' })
    const steps = wrapper.findAll('li.step')
    expect(steps).toHaveLength(2)
    expect(steps[0]!.text()).toContain('INSERT INTO t VALUES (1)')
    expect(steps[0]!.text()).toContain('Autorisée')
    expect(steps[1]!.text()).toContain('À confirmer')
    expect(steps[1]!.text()).toContain('WHERE')
    wrapper.unmount()
  })

  it('keeps the run button off when a statement is refused and names it', async () => {
    const wrapper = await previewed({
      canRun: false,
      steps: [{ index: 0, sql: 'COPY t TO x', verdict: 'refused', kind: null, reason: 'forbidden_construct' }],
      firstRefused: { index: 0, reason: 'forbidden_construct' },
    })

    expect(wrapper.get('button.execute').attributes('disabled')).toBeDefined()
    expect(wrapper.get('.refused-note').text()).toContain('instruction 1')
    wrapper.unmount()
  })

  it('warns that a script mixing data and DDL cannot be rolled back as a whole', async () => {
    const wrapper = await previewed({ atomic: false, warnings: ['mixed_data_and_ddl'], dryRun: { available: false, reason: 'implicit_commit' } })

    expect(wrapper.get('.warnings').text()).toContain('pas annulable en entier')
    wrapper.unmount()
  })

  it('disables the dry run with the reason shown when the engine cannot do it', async () => {
    const wrapper = await previewed({ transactional: false, atomic: false, warnings: ['no_transaction'], dryRun: { available: false, reason: 'no_transaction' } })

    expect(wrapper.get('input.dry-run').attributes('disabled')).toBeDefined()
    expect(wrapper.get('.dry-run-reason').text()).toContain('transaction')
    wrapper.unmount()
  })

  it('asks ONE confirmation for the whole script, then executes it confirmed', async () => {
    const wrapper = await previewed({ destructiveCount: 2 })

    await wrapper.get('button.execute').trigger('click')
    expect(mocked).toHaveBeenCalledTimes(1)
    expect(document.body.textContent).toContain('2 instructions destructrices')

    mocked.mockResolvedValueOnce(run())
    await document.body.querySelector<HTMLButtonElement>('.confirm')!.click()
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/script/execute', { script: 'INSERT INTO t VALUES (1); DELETE FROM t', confirmed: true, dryRun: false })
    expect(wrapper.get('.outcome').text()).toContain('Script exécuté')
    wrapper.unmount()
  })

  it('executes straight away when nothing is destructive, with no confirmation', async () => {
    const wrapper = await previewed({ needsConfirmation: false, destructiveCount: 0 })
    mocked.mockResolvedValueOnce(run())

    await wrapper.get('button.execute').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/script/execute', { script: 'INSERT INTO t VALUES (1); DELETE FROM t', confirmed: false, dryRun: false })
    wrapper.unmount()
  })

  it('tests without committing when the box is ticked and says nothing was kept', async () => {
    const wrapper = await previewed({ needsConfirmation: false, destructiveCount: 0 })
    await wrapper.get('input.dry-run').setValue(true)
    mocked.mockResolvedValueOnce(run({ status: 'dry_run', dryRun: true, applied: [], steps: run().steps.map((s) => ({ ...s, status: 'rolled_back' as const })) }))

    await wrapper.get('button.execute').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/script/execute', { script: 'INSERT INTO t VALUES (1); DELETE FROM t', confirmed: false, dryRun: true })
    expect(wrapper.get('.outcome').text()).toContain('rien n’a été validé')
    wrapper.unmount()
  })

  it('reports a failure in the middle: which statement, which stayed applied, which did not run', async () => {
    const wrapper = await previewed({ needsConfirmation: false, destructiveCount: 0 })
    mocked.mockResolvedValueOnce(run({
      status: 'partial',
      failureReason: 'engine_error',
      applied: [0],
      failed: 1,
      notRun: [2],
      steps: [
        { index: 0, sql: 'CREATE TABLE a (id int)', status: 'applied', affectedRows: 0, returnedRows: null, error: null, durationMs: 1 },
        { index: 1, sql: 'INSERT INTO a VALUES (1, 2)', status: 'failed', affectedRows: null, returnedRows: null, error: 'column count mismatch', durationMs: 1 },
        { index: 2, sql: 'SELECT 1', status: 'not_run', affectedRows: null, returnedRows: null, error: null, durationMs: 0 },
      ],
    }))

    await wrapper.get('button.execute').trigger('click')
    await flushPromises()

    expect(wrapper.get('.outcome').text()).toContain('1 instruction reste appliquée')
    const steps = wrapper.findAll('li.step')
    expect(steps.map((s) => s.attributes('data-status'))).toEqual(['applied', 'failed', 'not_run'])
    expect(steps[1]!.text()).toContain('column count mismatch')
    wrapper.unmount()
  })

  it('says everything was cancelled when a transaction rolled back', async () => {
    const wrapper = await previewed({ needsConfirmation: false, destructiveCount: 0 })
    mocked.mockResolvedValueOnce(run({ status: 'rolled_back', failureReason: 'engine_error', applied: [], failed: 1, notRun: [], steps: [
      { index: 0, sql: 'INSERT INTO t VALUES (1)', status: 'rolled_back', affectedRows: 1, returnedRows: null, error: null, durationMs: 1 },
      { index: 1, sql: 'INSERT INTO t VALUES (1)', status: 'failed', affectedRows: null, returnedRows: null, error: 'duplicate key', durationMs: 1 },
    ] }))

    await wrapper.get('button.execute').trigger('click')
    await flushPromises()

    expect(wrapper.get('.outcome').text()).toContain('tout a été annulé')
    wrapper.unmount()
  })

  it('shows the server error and keeps the panel usable', async () => {
    const wrapper = await previewed({ needsConfirmation: false, destructiveCount: 0 })
    mocked.mockRejectedValueOnce(new ApiError(403, 'script_refused', { reason: 'write_not_permitted', statement: 3 }))

    await wrapper.get('button.execute').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('instruction 4')
    wrapper.unmount()
  })

  it('forgets the preview as soon as the script changes', async () => {
    const wrapper = await previewed()

    await wrapper.setProps({ script: 'SELECT 1' })

    expect(wrapper.findAll('li.step')).toHaveLength(0)
    expect(wrapper.get('button.execute').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
