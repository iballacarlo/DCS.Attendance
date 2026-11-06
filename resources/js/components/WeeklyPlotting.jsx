import { time12 } from '../lib/format';
import { plottingDays, plottingTime, weeklyPlotting } from '../lib/weeklyPlotting';

const typeLabel = (block) => block.schedule_type === 'consultation' ? 'Consultation' : block.schedule_type === 'lunch_break' ? 'Lunch break' : block.class_mode === 'laboratory' ? 'Laboratory' : block.class_mode === 'lecture' ? 'Lecture' : 'Class';

export default function WeeklyPlotting({ blocks, subjectNames = {} }) {
    const model = weeklyPlotting(blocks);
    const subjectRows = new Map();
    model.courses.forEach((course) => subjectRows.set(course.subject, (subjectRows.get(course.subject) || 0) + 1));
    const mobileDays = plottingDays.map((name, index) => ({
        name,
        blocks: blocks.filter((block) => Number(block.day_of_week) === index + 1)
            .slice().sort((left, right) => left.start_time.localeCompare(right.start_time)),
    }));
    return <section className="weekly-plotting" aria-label="Weekly faculty class plotting">
        <div className="wp-scroll" tabIndex={0} aria-label="Weekly schedule table; scroll horizontally on smaller screens"><table className="wp-grid"><colgroup><col className="wp-time-col" /><col className="wp-time-col" />{plottingDays.map((day) => <col key={day} />)}</colgroup><thead><tr><th colSpan={2}>Time</th>{plottingDays.map((day) => <th key={day} rowSpan={2}>{day}</th>)}</tr><tr><th>From</th><th>To</th></tr></thead><tbody>{model.rows.map((row) => <tr key={row.start}><th scope="row">{time12(plottingTime(row.start))}</th><td className="wp-time-to">{time12(plottingTime(row.end))}</td>{row.cells.map((cell, day) => cell === null ? null : <td key={day} rowSpan={cell.span} className={cell.block ? `wp-block wp-${cell.block.schedule_type === 'class' ? cell.block.class_mode || 'lecture' : cell.block.schedule_type}` : 'wp-empty'}>{cell.block && <><strong>{cell.block.schedule_type === 'class' ? cell.block.subject || 'Not specified' : typeLabel(cell.block)}</strong>{cell.block.schedule_type === 'class' && <><span>{cell.block.section || 'Not specified'}</span><span>{typeLabel(cell.block)}</span></>}<small>{time12(cell.block.start_time)} – {time12(cell.block.end_time)}</small></>}</td>)}</tr>)}</tbody></table></div>
        <div className="wp-mobile-schedule" aria-label="Weekly schedule by day">
            {mobileDays.map((day) => <section className="wp-mobile-day" key={day.name}>
                <header><strong>{day.name}</strong><span>{day.blocks.length} {day.blocks.length === 1 ? 'block' : 'blocks'}</span></header>
                {day.blocks.length ? <div className="wp-mobile-blocks">{day.blocks.map((block, index) => <article className={`wp-mobile-block wp-${block.schedule_type === 'class' ? block.class_mode || 'lecture' : block.schedule_type}`} key={`${block.id || index}-${block.start_time}`}>
                    <div className="wp-mobile-time"><strong>{time12(block.start_time)}</strong><span>{time12(block.end_time)}</span></div>
                    <div className="wp-mobile-details"><small>{typeLabel(block)}</small><strong>{block.schedule_type === 'class' ? block.subject || 'Not specified' : typeLabel(block)}</strong>{block.schedule_type === 'class' && <span>{block.section || 'Not specified'}{subjectNames[block.subject] ? ` · ${subjectNames[block.subject]}` : ''}</span>}</div>
                </article>)}</div> : <p>No scheduled classes or activities.</p>}
            </section>)}
        </div>
        <div className="wp-summary"><table><thead><tr><th>Subject</th><th>Section</th><th>Lecture hours / week</th><th>Laboratory hours / week</th><th>Total class hours / week</th></tr></thead><tbody>{model.courses.length ? model.courses.map((course, index) => <tr key={`${course.subject}-${course.section}`}>{(index === 0 || model.courses[index - 1].subject !== course.subject) && <td className="wp-subject-cell" rowSpan={subjectRows.get(course.subject)}>{course.subject}{(subjectNames[course.subject] || subjectNames[`${course.subject}A`]) && ` - ${subjectNames[course.subject] || subjectNames[`${course.subject}A`]}`}</td>}<td>{course.section}</td><td>{course.lecture.toFixed(2)}</td><td>{course.laboratory.toFixed(2)}</td><td>{(course.lecture + course.laboratory + course.unspecified).toFixed(2)}</td></tr>) : <tr><td colSpan={5}>No class blocks assigned.</td></tr>}</tbody></table></div>
        <section className="wp-mobile-summary" aria-label="Weekly class hours">
            <h3>Weekly class hours</h3>
            {model.courses.length ? model.courses.map((course) => <article key={`${course.subject}-${course.section}`}>
                <div><strong>{course.subject}</strong><span>{course.section}</span>{subjectNames[course.subject] && <small>{subjectNames[course.subject]}</small>}</div>
                <dl><div><dt>Lecture</dt><dd>{course.lecture.toFixed(2)}</dd></div><div><dt>Laboratory</dt><dd>{course.laboratory.toFixed(2)}</dd></div><div><dt>Total</dt><dd>{(course.lecture + course.laboratory + course.unspecified).toFixed(2)}</dd></div></dl>
            </article>) : <p>No class blocks assigned.</p>}
        </section>
    </section>;
}
