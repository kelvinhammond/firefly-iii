<?php
/**
 * AccountController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use ExpandedForm;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Collector\JournalCollectorInterface;
use FireflyIII\Http\Requests\AccountFormRequest;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Log;
use Preferences;
use Steam;
use View;

/**
 * Class AccountController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AccountController extends Controller
{
    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        // translations:
        $this->middleware(
            function ($request, $next) {
                app('view')->share('mainTitleIcon', 'fa-credit-card');
                app('view')->share('title', trans('firefly.accounts'));

                return $next($request);
            }
        );
    }

    /**
     * @param Request $request
     * @param string  $what
     *
     * @return View
     */
    public function create(Request $request, string $what = 'asset')
    {
        /** @var CurrencyRepositoryInterface $repository */
        $repository         = app(CurrencyRepositoryInterface::class);
        $allCurrencies      = $repository->get();
        $currencySelectList = ExpandedForm::makeSelectList($allCurrencies);
        $defaultCurrency    = app('amount')->getDefaultCurrency();
        $subTitleIcon       = config('firefly.subIconsByIdentifier.' . $what);
        $subTitle           = trans('firefly.make_new_' . $what . '_account');
        $roles              = [];
        foreach (config('firefly.accountRoles') as $role) {
            $roles[$role] = strval(trans('firefly.account_role_' . $role));
        }

        // pre fill some data
        $request->session()->flash('preFilled', ['currency_id' => $defaultCurrency->id]);

        // put previous url in session if not redirect from store (not "create another").
        if (true !== session('accounts.create.fromStore')) {
            $this->rememberPreviousUri('accounts.create.uri');
        }
        $request->session()->forget('accounts.create.fromStore');

        return view('accounts.create', compact('subTitleIcon', 'what', 'subTitle', 'currencySelectList', 'allCurrencies', 'roles'));
    }

    /**
     * @param AccountRepositoryInterface $repository
     * @param Account                    $account
     *
     * @return View
     */
    public function delete(AccountRepositoryInterface $repository, Account $account)
    {
        $typeName    = config('firefly.shortNamesByFullName.' . $account->accountType->type);
        $subTitle    = trans('firefly.delete_' . $typeName . '_account', ['name' => $account->name]);
        $accountList = ExpandedForm::makeSelectListWithEmpty($repository->getAccountsByType([$account->accountType->type]));
        unset($accountList[$account->id]);

        // put previous url in session
        $this->rememberPreviousUri('accounts.delete.uri');

        return view('accounts.delete', compact('account', 'subTitle', 'accountList'));
    }

    /**
     * @param Request                    $request
     * @param AccountRepositoryInterface $repository
     * @param Account                    $account
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function destroy(Request $request, AccountRepositoryInterface $repository, Account $account)
    {
        $type     = $account->accountType->type;
        $typeName = config('firefly.shortNamesByFullName.' . $type);
        $name     = $account->name;
        $moveTo   = $repository->find(intval($request->get('move_account_before_delete')));

        $repository->destroy($account, $moveTo);

        $request->session()->flash('success', strval(trans('firefly.' . $typeName . '_deleted', ['name' => $name])));
        Preferences::mark();

        return redirect($this->getPreviousUri('accounts.delete.uri'));
    }

    /**
     * Edit an account.
     *
     * @param Request $request
     * @param Account $account
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) // long and complex but not that excessively so.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @return View
     *
     * @throws FireflyException
     * @throws FireflyException
     * @throws FireflyException
     */
    public function edit(Request $request, Account $account)
    {
        /** @var CurrencyRepositoryInterface $repository */
        $repository         = app(CurrencyRepositoryInterface::class);
        $what               = config('firefly.shortNamesByFullName')[$account->accountType->type];
        $subTitle           = trans('firefly.edit_' . $what . '_account', ['name' => $account->name]);
        $subTitleIcon       = config('firefly.subIconsByIdentifier.' . $what);
        $allCurrencies      = $repository->get();
        $currencySelectList = ExpandedForm::makeSelectList($allCurrencies);
        $roles              = [];
        foreach (config('firefly.accountRoles') as $role) {
            $roles[$role] = strval(trans('firefly.account_role_' . $role));
        }

        // put previous url in session if not redirect from store (not "return_to_edit").
        if (true !== session('accounts.edit.fromUpdate')) {
            $this->rememberPreviousUri('accounts.edit.uri');
        }
        $request->session()->forget('accounts.edit.fromUpdate');

        // pre fill some useful values.

        // the opening balance is tricky:
        $openingBalanceAmount = $account->getOpeningBalanceAmount();
        $openingBalanceAmount = '0' === $account->getOpeningBalanceAmount() ? '' : $openingBalanceAmount;
        $openingBalanceDate   = $account->getOpeningBalanceDate();
        $openingBalanceDate   = 1900 === $openingBalanceDate->year ? null : $openingBalanceDate->format('Y-m-d');
        $currency             = $repository->find(intval($account->getMeta('currency_id')));

        $preFilled = [
            'accountNumber'        => $account->getMeta('accountNumber'),
            'accountRole'          => $account->getMeta('accountRole'),
            'ccType'               => $account->getMeta('ccType'),
            'ccMonthlyPaymentDate' => $account->getMeta('ccMonthlyPaymentDate'),
            'BIC'                  => $account->getMeta('BIC'),
            'openingBalanceDate'   => $openingBalanceDate,
            'openingBalance'       => $openingBalanceAmount,
            'virtualBalance'       => $account->virtual_balance,
            'currency_id'          => $currency->id,
        ];
        $request->session()->flash('preFilled', $preFilled);

        return view(
            'accounts.edit',
            compact(
                'allCurrencies',
                'currencySelectList',
                'account',
                'currency',
                'subTitle',
                'subTitleIcon',
                'what',
                'roles',
                'preFilled'
            )
        );
    }

    /**
     * @param Request                    $request
     * @param AccountRepositoryInterface $repository
     * @param string                     $what
     *
     * @return View
     */
    public function index(Request $request, AccountRepositoryInterface $repository, string $what)
    {
        $what         = $what ?? 'asset';
        $subTitle     = trans('firefly.' . $what . '_accounts');
        $subTitleIcon = config('firefly.subIconsByIdentifier.' . $what);
        $types        = config('firefly.accountTypesByIdentifier.' . $what);
        $collection   = $repository->getAccountsByType($types);
        $total        = $collection->count();
        $page         = 0 === intval($request->get('page')) ? 1 : intval($request->get('page'));
        $pageSize     = intval(Preferences::get('listPageSize', 50)->data);
        $accounts     = $collection->slice(($page - 1) * $pageSize, $pageSize);
        unset($collection);
        /** @var Carbon $start */
        $start = clone session('start', Carbon::now()->startOfMonth());
        /** @var Carbon $end */
        $end = clone session('end', Carbon::now()->endOfMonth());
        $start->subDay();

        $ids           = $accounts->pluck('id')->toArray();
        $startBalances = Steam::balancesByAccounts($accounts, $start);
        $endBalances   = Steam::balancesByAccounts($accounts, $end);
        $activities    = Steam::getLastActivities($ids);

        $accounts->each(
            function (Account $account) use ($activities, $startBalances, $endBalances) {
                $account->lastActivityDate = $this->isInArray($activities, $account->id);
                $account->startBalance     = $this->isInArray($startBalances, $account->id);
                $account->endBalance       = $this->isInArray($endBalances, $account->id);
                $account->difference       = bcsub($account->endBalance, $account->startBalance);
            }
        );

        // make paginator:
        $accounts = new LengthAwarePaginator($accounts, $total, $pageSize, $page);
        $accounts->setPath(route('accounts.index', [$what]));

        return view('accounts.index', compact('what', 'subTitleIcon', 'subTitle', 'page', 'accounts'));
    }

    /**
     * Show an account.
     *
     * @param Request                    $request
     * @param JournalRepositoryInterface $repository
     * @param Account                    $account
     * @param string                     $moment
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|View
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) // long and complex but not that excessively so.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @throws FireflyException
     */
    public function show(Request $request, JournalRepositoryInterface $repository, Account $account, string $moment = '')
    {
        if (AccountType::INITIAL_BALANCE === $account->accountType->type) {
            return $this->redirectToOriginalAccount($account);
        }
        /** @var CurrencyRepositoryInterface $currencyRepos */
        $currencyRepos = app(CurrencyRepositoryInterface::class);
        $range         = Preferences::get('viewRange', '1M')->data;
        $subTitleIcon  = config('firefly.subIconsByIdentifier.' . $account->accountType->type);
        $page          = intval($request->get('page'));
        $pageSize      = intval(Preferences::get('listPageSize', 50)->data);
        $chartUri      = route('chart.account.single', [$account->id]);
        $start         = null;
        $end           = null;
        $periods       = new Collection;
        $currencyId    = intval($account->getMeta('currency_id'));
        $currency      = $currencyRepos->find($currencyId);
        if (0 === $currencyId) {
            $currency = app('amount')->getDefaultCurrency(); // @codeCoverageIgnore
        }

        // prep for "all" view.
        if ('all' === $moment) {
            $subTitle = trans('firefly.all_journals_for_account', ['name' => $account->name]);
            $chartUri = route('chart.account.all', [$account->id]);
            $first    = $repository->first();
            $start    = $first->date ?? new Carbon;
            $end      = new Carbon;
        }

        // prep for "specific date" view.
        if (strlen($moment) > 0 && 'all' !== $moment) {
            $start    = new Carbon($moment);
            $end      = app('navigation')->endOfPeriod($start, $range);
            $fStart   = $start->formatLocalized($this->monthAndDayFormat);
            $fEnd     = $end->formatLocalized($this->monthAndDayFormat);
            $subTitle = trans('firefly.journals_in_period_for_account', ['name' => $account->name, 'start' => $fStart, 'end' => $fEnd]);
            $chartUri = route('chart.account.period', [$account->id, $start->format('Y-m-d')]);
            $periods  = $this->getPeriodOverview($account);
        }

        // prep for current period view
        if (0 === strlen($moment)) {
            $start    = clone session('start', app('navigation')->startOfPeriod(new Carbon, $range));
            $end      = clone session('end', app('navigation')->endOfPeriod(new Carbon, $range));
            $fStart   = $start->formatLocalized($this->monthAndDayFormat);
            $fEnd     = $end->formatLocalized($this->monthAndDayFormat);
            $subTitle = trans('firefly.journals_in_period_for_account', ['name' => $account->name, 'start' => $fStart, 'end' => $fEnd]);
            $periods  = $this->getPeriodOverview($account);
        }

        // grab journals:
        $collector = app(JournalCollectorInterface::class);
        $collector->setAccounts(new Collection([$account]))->setLimit($pageSize)->setPage($page);
        if (null !== $start) {
            $collector->setRange($start, $end);
        }
        $transactions = $collector->getPaginatedJournals();
        $transactions->setPath(route('accounts.show', [$account->id, $moment]));

        return view(
            'accounts.show',
            compact('account', 'currency', 'moment', 'periods', 'subTitleIcon', 'transactions', 'subTitle', 'start', 'end', 'chartUri')
        );
    }

    /**
     * @param AccountFormRequest         $request
     * @param AccountRepositoryInterface $repository
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store(AccountFormRequest $request, AccountRepositoryInterface $repository)
    {
        $data    = $request->getAccountData();
        $account = $repository->store($data);
        $request->session()->flash('success', strval(trans('firefly.stored_new_account', ['name' => $account->name])));
        Preferences::mark();

        // update preferences if necessary:
        $frontPage = Preferences::get('frontPageAccounts', [])->data;
        if (count($frontPage) > 0 && AccountType::ASSET === $account->accountType->type) {
            // @codeCoverageIgnoreStart
            $frontPage[] = $account->id;
            Preferences::set('frontPageAccounts', $frontPage);
            // @codeCoverageIgnoreEnd
        }

        if (1 === intval($request->get('create_another'))) {
            // set value so create routine will not overwrite URL:
            $request->session()->put('accounts.create.fromStore', true);

            return redirect(route('accounts.create', [$request->input('what')]))->withInput();
        }

        // redirect to previous URL.
        return redirect($this->getPreviousUri('accounts.create.uri'));
    }

    /**
     * @param AccountFormRequest         $request
     * @param AccountRepositoryInterface $repository
     * @param Account                    $account
     *
     * @return $this|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function update(AccountFormRequest $request, AccountRepositoryInterface $repository, Account $account)
    {
        $data = $request->getAccountData();
        $repository->update($account, $data);

        $request->session()->flash('success', strval(trans('firefly.updated_account', ['name' => $account->name])));
        Preferences::mark();

        if (1 === intval($request->get('return_to_edit'))) {
            // set value so edit routine will not overwrite URL:
            $request->session()->put('accounts.edit.fromUpdate', true);

            return redirect(route('accounts.edit', [$account->id]))->withInput(['return_to_edit' => 1]);
        }

        // redirect to previous URL.
        return redirect($this->getPreviousUri('accounts.edit.uri'));
    }

    /**
     * @param array $array
     * @param int   $entryId
     *
     * @return null|mixed
     */
    protected function isInArray(array $array, int $entryId)
    {
        if (isset($array[$entryId])) {
            return $array[$entryId];
        }

        return '0';
    }

    /**
     * This method returns "period entries", so nov-2015, dec-2015, etc etc (this depends on the users session range)
     * and for each period, the amount of money spent and earned. This is a complex operation which is cached for
     * performance reasons.
     *
     * @param Account $account the account involved
     *
     * @return Collection
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function getPeriodOverview(Account $account): Collection
    {
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $start      = $repository->oldestJournalDate($account);
        $range      = Preferences::get('viewRange', '1M')->data;
        $start      = app('navigation')->startOfPeriod($start, $range);
        $end        = app('navigation')->endOfX(new Carbon, $range, null);
        $entries    = new Collection;
        $count      = 0;
        // properties for cache
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('account-show-period-entries');
        $cache->addProperty($account->id);

        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        Log::debug('Going to get period expenses and incomes.');
        while ($end >= $start && $count < 90) {
            $end        = app('navigation')->startOfPeriod($end, $range);
            $currentEnd = app('navigation')->endOfPeriod($end, $range);

            // try a collector for income:
            /** @var JournalCollectorInterface $collector */
            $collector = app(JournalCollectorInterface::class);
            $collector->setAccounts(new Collection([$account]))->setRange($end, $currentEnd)->setTypes([TransactionType::DEPOSIT])->withOpposingAccount();
            $earned = strval($collector->getJournals()->sum('transaction_amount'));

            // try a collector for expenses:
            /** @var JournalCollectorInterface $collector */
            $collector = app(JournalCollectorInterface::class);
            $collector->setAccounts(new Collection([$account]))->setRange($end, $currentEnd)->setTypes([TransactionType::WITHDRAWAL])->withOpposingAccount();
            $spent    = strval($collector->getJournals()->sum('transaction_amount'));
            $dateStr  = $end->format('Y-m-d');
            $dateName = app('navigation')->periodShow($end, $range);
            $entries->push(
                [
                    'string' => $dateStr,
                    'name'   => $dateName,
                    'spent'  => $spent,
                    'earned' => $earned,
                    'date'   => clone $end,]
            );
            $end = app('navigation')->subtractPeriod($end, $range, 1);
            ++$count;
        }
        $cache->store($entries);

        return $entries;
    }

    /**
     * @param Account $account
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     *
     * @throws FireflyException
     */
    private function redirectToOriginalAccount(Account $account)
    {
        /** @var Transaction $transaction */
        $transaction = $account->transactions()->first();
        if (null === $transaction) {
            throw new FireflyException('Expected a transaction. This account has none. BEEP, error.');
        }

        $journal = $transaction->transactionJournal;
        /** @var Transaction $opposingTransaction */
        $opposingTransaction = $journal->transactions()->where('transactions.id', '!=', $transaction->id)->first();

        if (null === $opposingTransaction) {
            throw new FireflyException('Expected an opposing transaction. This account has none. BEEP, error.'); // @codeCoverageIgnore
        }

        return redirect(route('accounts.show', [$opposingTransaction->account_id]));
    }
}
