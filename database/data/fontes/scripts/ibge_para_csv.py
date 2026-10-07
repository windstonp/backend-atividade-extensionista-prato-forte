import re,csv,collections
lines=open('ibge.txt').read().replace('\f','').split('\n')
ini=next(i for i,l in enumerate(lines) if 'Tabela 1 - Energia, macronutrientes e fibra na composição' in l)
fim=next(i for i,l in enumerate(lines) if i>ini and 'Tabela 2 - ' in l and 'composição' in l)
rx=re.compile(r'^(\d{7})\s+(\S.*?)\s{2,}(\d{1,2})\s+(\S.*?)\s{2,}([\d.,]+|-)\s+([\d.,]+|-)\s+([\d.,]+|-)\s+([\d.,]+|-)(?:\s+([\d.,]+|-))?\s*$')
cat=None; rows=[]; ruins=0
for i,l in enumerate(lines[ini:fim]):
    s=l.strip()
    m=rx.match(l)
    if m:
        rows.append(dict(code=m.group(1),desc=m.group(2),prep=m.group(4),kcal=m.group(5),prot=m.group(6),fat=m.group(7),carb=m.group(8),cat=cat)); continue
    if re.match(r'^\d{7}',l): ruins+=1
    if s and not re.search(r'\d',s) and len(s)<60 and l.startswith(' '*20) and 'Tabela' not in s and 'continuação' not in s and 'Código' not in s and '(g)' not in s and '(kcal)' not in s and 'Lipídios' not in s and 'Energia' not in s and 'Fibra' not in s and 'Carboi' not in s and 'total' not in s and 'totais' not in s and 'drato' not in s and 'alimentar' not in s:
        cat=s
print(len(rows),'linhas; ruins',ruins)
print(collections.Counter(r['cat'] for r in rows).most_common(30))
print(collections.Counter(r['prep'] for r in rows).most_common(15))

PREPS={'Não se aplica':None,'Cozido(a)':('cozido','cozida'),'Cru(a)':('cru','crua'),'Frito(a)':('frito','frita'),'Assado(a)':('assado','assada'),
       'Grelhado(a)/brasa/churrasco':('grelhado','grelhada'),'Refogado(a)':('refogado','refogada'),'Empanado(a)/à milanesa':('empanado','empanada')}
LEG=re.compile(r'feij|lentilha|grão-de-bico|grao-de-bico|ervilha|soja|fava',re.I)
def categoria(cat,nome):
    m={'Carnes e vísceras':'Carnes e derivados','Carnes industrializadas':'Carnes e derivados','Hortaliças folhosas, frutosas e outras':'Verduras, hortaliças e derivados',
       'Hortaliças tuberosas':'Verduras, hortaliças e derivados','Miscelâneas':'Miscelâneas','Açúcares e produtos de confeitaria':'Produtos açucarados',
       'Panificados':'Cereais e derivados','Farinhas, féculas e massas':'Cereais e derivados','Frutas':'Frutas e derivados','Pescados e frutos do mar':'Pescados e frutos do mar',
       'Laticínios':'Leite e derivados','Bebidas não alcoólicas e infusões':'Bebidas (alcoólicas e não alcoólicas)','Bebidas alcoólicas':'Bebidas (alcoólicas e não alcoólicas)',
       'Enlatados e conservas':'Alimentos preparados','Cocos, castanhas e nozes':'Nozes e sementes','Sais e condimentos':'Miscelâneas','Óleos e gorduras':'Gorduras e óleos'}
    if cat=='Aves e ovos': return 'Ovos e derivados' if nome.lower().startswith('ovo') else 'Carnes e derivados'
    if cat=='Cereais e leguminosas': return 'Leguminosas e derivados' if LEG.search(nome) else 'Cereais e derivados'
    return m.get(cat,'Outros')
def gerar(caminho):
    n=0
    with open(caminho,'w',newline='') as f:
        w=csv.writer(f); w.writerow(['name','category','kcal','protein','carbs','fat','source'])
        for r in rows:
            if r['prep'] != 'Não se aplica' or 'orgânic' in r['desc'].lower(): continue  # só o alimento puro; orgânico repete os valores
            nome=r['desc'].strip()
            p=PREPS[r['prep']]
            if p: nome=f"{nome}, {p[1] if nome.split()[0].split(',')[0].lower().endswith('a') else p[0]}"
            num=lambda v: '' if v=='-' else v.replace('.','')
            w.writerow([nome,categoria(r['cat'],nome),num(r['kcal']),num(r['prot']),num(r['carb']),num(r['fat']),'IBGE POF 2008-2009']); n+=1
    print('ibge escritas',n)
